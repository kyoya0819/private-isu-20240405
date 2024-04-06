<?php

$_SERVER += ["PATH_INFO" => $_SERVER["REQUEST_URI"]];
$_SERVER["SCRIPT_NAME"] = "/" . basename($_SERVER["SCRIPT_FILENAME"]);

const POSTS_PER_PAGE = 20;
const UPLOAD_LIMIT = 10 * 1024 * 1024;

// memcached session
$memd_addr = "127.0.0.1:11211";
if (isset($_SERVER["ISUCONP_MEMCACHED_ADDRESS"])) {
    $memd_addr = $_SERVER["ISUCONP_MEMCACHED_ADDRESS"];
}
ini_set("session.save_handler", "memcached");
ini_set("session.save_path", $memd_addr);

session_start();

require dirname(__FILE__) . "/bootstrap/containers.php";
require dirname(__FILE__) . "/bootstrap/helpers.php";
require dirname(__FILE__) . "/routes/routes.php";