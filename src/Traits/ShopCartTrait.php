<?php

namespace Amsgames\LaravelShop\Traits;

/**
 * This file is part of LaravelShop,
 * A shop solution for Laravel.
 *
 * @author Alejandro Mostajo
 * @copyright Amsgames, LLC
 * @license MIT
 * @package Amsgames\LaravelShop
 */

use Shop;
use Cookie;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

trait ShopCartTrait
{
    /**
     * Property used to stored calculations.
     * @var array
     */
    private $cartCalculations = null;

    /**
     * Boot the user model
     * Attach event listener to remove the relationship records when trying to delete
     * Will NOT delete any records if the user model uses soft deletes.
     *
     * @return void|bool
     */
    public static function boot()
    {
        parent::boot();

        static::deleting(function($user) {
            if (!method_exists(Config::get('auth.providers.users.model'), 'bootSoftDeletingTrait')) {
                $user->items()->sync([]);
            }

            return true;
        });
    }

    /**
     * One-to-One relations with the user model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function user()
    {
        return $this->belongsTo(Config::get('auth.providers.users.model'), 'user_id');
    }

    /**
     * One-to-Many relations with Item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function items()
    {
        return $this->hasMany(Config::get('shop.item'), 'cart_id');
    }

    /**
     * Empty cart.
     *
     */
    public function emptyCart()
    {
        $this->items()->delete();
        $this->resetCalculations();
    }

    /**
     * Adds item to cart.
     *
     * @param mixed $item     Item to add, can be an Store Item, a Model with ShopItemTrait or an array.
     * @param int   $quantity Item quantity in cart.
     */
    public function add($item, $attributes = [], $quantity = 1, $quantityReset = false)
    {
        if (!is_array($item) && !$item->isShoppable) return;

        $attributesHash = sha1(serialize($attributes));
        $cartItem = $this->getItem(is_array($item) ? $item['sku'] : $item->sku, $attributesHash);

        // Add new or sum quantity
        if (empty($cartItem)) {
            $reflection = null;
            if (is_object($item)) {
                $reflection = new \ReflectionClass($item);
            }

            $class = Config::get('shop.item');
            $cartItem = new $class();
            $cartItem->user_id = !empty($this->user->shopId) ? $this->user->shopId : 0;
            $cartItem->session_id = session('visitor_id');
            $cartItem->cart_id = $this->id;
            $cartItem->sku = is_array($item) ? $item['sku'] : $item->sku;
            $cartItem->price = is_array($item) ? $item['price'] : $item->price;
            $cartItem->tax = is_array($item)
                                        ? (array_key_exists('tax', $item)
                                            ?   $item['tax']
                                            :   0
                                        )
                                        : (isset($item->tax) && !empty($item->tax)
                                            ?   $item->tax
                                            :   0
                                        );
            $cartItem->shipping = is_array($item)
                                        ? (array_key_exists('shipping', $item)
                                            ?   $item['shipping']
                                            :   0
                                        )
                                        : (isset($item->shipping) && !empty($item->shipping)
                                            ?   $item->shipping
                                            :   0
                                        );
            $cartItem->discount = $item->calculateDiscount();
            $cartItem->currency = Config::get('shop.currency');
            $cartItem->quantity = $quantity;
            $cartItem->attributes_hash = $attributesHash;

            // This looks a bit backwards but this is the proper way of storing the relation
            $item->shoppables()->save($cartItem);

            if (!empty($attributes) && is_array($attributes)) {
                foreach ($attributes as $attributeType => $attributeValues) {
                    if ($type = Config::get('shop.attribute_models.' . $attributeType)) {
                        if (is_array($attributeValues)) {
                            foreach ($attributeValues as $cgr => $cAttr) {
                                $attr = new $type();
                                $attr->fillAttr($cgr, $cAttr);

                                $class = Config::get('shop.item_attributes');
                                $itemAttribute = new $class();
                                // dd($itemAttribute);
                                $itemAttribute->atributeObject()->associate($attr);

                                $cartItem->itemAttributes()->save($itemAttribute);

                            }
                        }
                    }
                }
            }
/*
            if (!empty($attributes['attribute']) && is_array($attributes['attribute'])) {
                foreach($attributes['attribute'] as $cGr => $cAttr) {
                    $cartItemAttributes = call_user_func( Config::get('shop.item_attributes') . '::create', [
                        'item_id'                       => $cartItem->id,
                        'group_value'                   => $cGr,
                        'attribute_class'               => 'TypiCMS\Modules\Attributes\Shells\Models\Attribute',
                        'attribute_reference_id'        => $cAttr,
                        'attribute_value'               => null,
                    ]);
                }

            }

            if (!empty($attributes['custom']) && is_array($attributes['custom'])) {

                foreach($attributes['custom'] as $cGr => $cAttr) {
                    $cartItemAttributes = call_user_func( Config::get('shop.item_attributes') . '::create', [
                        'item_id'                   => $cartItem->id,
                        'group_value'               => $cGr,
                        'attribute_class'           => 'TypiCMS\Modules\Attributes\Shells\Models\Attribute',
                        'attribute_reference_id'    => null,
                        'attribute_value'           => $cAttr,
                    ]);
                }

            }
*/
            $this->resetCalculations();
        } else {
            $this->increase($cartItem, $quantity, $quantityReset);
        }

        return $this;
    }

    /**
     * Directly increase already existing item
     *
     * @param Item  $item     Item to add.
     * @param int   $quantity Item quantity in cart.
     * @return Item
     */
    public function increase($item, $quantity = 1, $quantityReset = false)
    {
        $item->quantity = $quantityReset
            ? $quantity
            : $item->quantity + $quantity;
        $item->save();

        $this->resetCalculations();
        return $this;
    }

    /**
     * Removes an item from the cart or decreases its quantity.
     * Returns flag indicating if removal was successful.
     *
     * @param mixed $item     Item to remove, can be an Store Item, a Model with ShopItemTrait or an array.
     * @param int   $quantity Item quantity to decrease. 0 if wanted item to be removed completly.
     *
     * @return bool
     */
    public function remove($item, $quantity = 0)
    {
        // Get item
        //$cartItem = $this->getItem(is_array($item) ? $item['sku'] : $item->sku, $item->attributes_hash);
        // Remove or decrease quantity

        if (!empty($quantity)) {
            $item->quantity -= $quantity;
            $item->save();
            if ($item->quantity > 0) return true;
        }
        $item->delete();

        $this->resetCalculations();
        return $this;
    }

    /**
     * Checks if the user has a role by its name.
     *
     * @param string|array $name       Role name or array of role names.
     * @param bool         $requireAll All roles in the array are required.
     *
     * @return bool
     */
    public function hasItem($sku, $requireAll = false)
    {
        if (is_array($sku)) {
            foreach ($sku as $skuSingle) {
                $hasItem = $this->hasItem($skuSingle);

                if ($hasItem && !$requireAll) {
                    return true;
                } elseif (!$hasItem && $requireAll) {
                    return false;
                }
            }

            // If we've made it this far and $requireAll is FALSE, then NONE of the roles were found
            // If we've made it this far and $requireAll is TRUE, then ALL of the roles were found.
            // Return the value of $requireAll;
            return $requireAll;
        } else {
            foreach ($this->items as $item) {
                if ($item->sku == $sku) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Scope class by a given user ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query  Query.
     * @param mixed                                 $userId User ID.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWhereUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeWhereVisitorId($query)
    {
        return $query->where('session_id', session('visitor_id'));
    }

    /**
     * Scope to current user cart.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query  Query.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWhereCurrent($query)
    {
        return $query->whereVisitorId();
        //TODO: use user_id as well
        if (Auth::guest()) return $query->whereVisitorId();
        return $query->whereUser(Auth::user()->shopId);
    }

    /**
     * Scope to current user cart and returns class model.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query  Query.
     *
     * @return this
     */
    public function scopeCurrent($query)
    {
        $cart = $query->whereCurrent()->first();
        if (empty($cart)) {
            if (!Auth::guest()) {
                $cart = call_user_func( Config::get('shop.cart') . '::create', [
                    'user_id' =>  Auth::user()->shopId,
                    'session_id' =>  session('visitor_id')
                ]);
            } else {
                $cart = call_user_func( Config::get('shop.cart') . '::create', [
                    'session_id' =>  session('visitor_id')
                ]);
            }
        }

        return $cart;
    }

    /**
     * Scope to current user cart and returns class model.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query  Query.
     *
     * @return this
     */
    public function scopeFindByUser($query, $userId)
    {
        if (empty($userId)) return;
        $cart = $query->whereUser($userId)->first();
        if (empty($cart)) {
            $cart = call_user_func( Config::get('shop.cart') . '::create', [
                'user_id' =>  $userId
            ]);
        }
        return $cart;
    }

    /**
     * Transforms cart into an order.
     * Returns created order.
     *
     * @param string $statusCode Order status to create order with.
     *
     * @return Order
     */
    public function placeOrder($statusCode = null)
    {
        if (empty($statusCode)) $statusCode = Config::get('shop.order_status_placement');
        // Create order
        $order = call_user_func( Config::get('shop.order') . '::create', [
            'user_id'       => $this->user_id,
            'statusCode'    => $statusCode
        ]);
        // Map cart items into order
        for ($i = count($this->items) - 1; $i >= 0; --$i) {
            // Attach to order
            $this->items[$i]->order_id  = $order->id;
            // Remove from cart
            $this->items[$i]->cart_id   = null;
            // Update
            $this->items[$i]->save();
        }
        $this->resetCalculations();
        return $order;
    }

    /**
     * Whipes put cart
     */
    public function clear()
    {
        DB::table(Config::get('shop.item_table'))
            ->where('cart_id', $this->attributes['id'])
            ->delete();
        $this->resetCalculations();
        return $this;
    }

    /**
     * Retrieves item from cart;
     *
     * @param string $sku SKU of item.
     *
     * @return mixed
     */
    private function getItem($sku, $attributesHash)
    {
        $className  = Config::get('shop.item');
        $item       = new $className();
        return $item->where('sku', $sku)
            ->where('cart_id', $this->attributes['id'])
            ->where('attributes_hash', $attributesHash)
            ->first();
    }

}