<?php

namespace App\Support;

final class AkunNonaktifKedaluwarsa
{
    public static function hapusKedaluwarsa(): int
    {
        $api = new SupabaseApi;
        $akun = $api->usersNonaktifKedaluwarsa(now()->toDateTimeString());
        foreach ($akun as $user) {
            $api->deleteUser($user['id']);
        }

        return count($akun);
    }
}
