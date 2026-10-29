<?php

return [

    "paths" => ["api/*"],

    "allowed_methods" => ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],

    // Only the React app may call the API from a browser. Set CORS_ALLOWED_ORIGINS in .env
    // (comma-separated) when you deploy, e.g. https://reviewerhub.jru.edu
    "allowed_origins" => array_values(array_filter(array_map(
        "trim",
        explode(",", (string) env("CORS_ALLOWED_ORIGINS", "http://localhost:5173,http://127.0.0.1:5173"))
    ))),

    "allowed_origins_patterns" => [],

    "allowed_headers" => ["Accept", "Authorization", "Content-Type", "X-Requested-With"],

    "exposed_headers" => [],

    "max_age" => 0,

    // Tokens are sent in the Authorization header, not cookies.
    "supports_credentials" => false,

];
