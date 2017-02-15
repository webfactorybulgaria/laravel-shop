<?php

namespace Amsgames\LaravelShop\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use TypiCMS\Modules\Shop\Shells\Models\Cart;
use TypiCMS\Modules\Shop\Shells\Models\Item;

class UserLogged
{

    public function onLogin($login)
    {
          Cart::where('session_id', session('visitor_id'))
               ->update(['user_id' => $login->user->id]);
          Item::where('session_id', session('visitor_id'))
               ->update(['user_id' => $login->user->id]);

    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param Dispatcher $events
     *
     * @return array
     */
    public function subscribe($events)
    {
        $events->listen('Illuminate\Auth\Events\Login', 'Amsgames\LaravelShop\Events\UserLogged@onLogin');
    }
}
