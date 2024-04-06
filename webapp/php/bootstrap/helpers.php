<?php

use \Psr\Http\Message\ResponseInterface as Response;

function escape_html($h): string
{
    return htmlspecialchars($h, ENT_QUOTES | ENT_HTML5, "UTF-8");
}

function redirect(Response $response, $location, $status): Response
{
    return $response->withStatus($status)->withHeader("Location", $location);
}

function validate_user($account_name, $password): bool
{
    if (!(preg_match("/\A[0-9a-zA-Z_]{3,}\z/", $account_name) && preg_match("/\A[0-9a-zA-Z_]{6,}\z/", $password))) {
        return false;
    }
    return true;
}

function calculate_passhash($account_name, $password): string
{
    $salt = hash("sha512", $account_name);
    return hash("sha512", "{$password}:{$salt}");
}