<?php

use Illuminate\Support\Facades\Route;

Route::get('/test-db', function () {
    $connection = DB::connection('mongodb');
    $connection->getMongoClient()->listDatabases();
    return "MongoDB connected successfully!";
});