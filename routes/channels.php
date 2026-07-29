<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Broadcast::channel('chat.{id}', function ($user, $id) {
//     return (int) $user->id === (int) $id;
// });


Broadcast::channel('chat.{id}', function ($user, $id) {
    // Izinkan jika dia pemilik ID tsb, ATAU jika dia adalah Admin
    return (int) $user->id === (int) $id || in_array($user->usertype, ['admin', 'superadmin', 'cs']);
});
