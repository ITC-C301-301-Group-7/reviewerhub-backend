<?php

return [

    /*
    | Email domains allowed to register (comma-separated in .env).
    | Narrow this to the domains you really use, e.g. "jru.edu,my.jru.edu".
    */
    "allowed_email_domains" => array_values(array_filter(array_map(
        "trim",
        explode(",", strtolower((string) env("ALLOWED_EMAIL_DOMAINS", "jru.edu,my.jru.edu,jru.edu.ph")))
    ))),

    /*
    | Faculty accounts can only be created with this invite code.
    | Leave it empty to turn public faculty registration OFF completely.
    */
    "faculty_invite_code" => (string) env("FACULTY_INVITE_CODE", ""),

    /*
    | Where the React app lives. Password-reset emails link to this address.
    */
    "frontend_url" => rtrim((string) env("FRONTEND_URL", "http://localhost:5173"), "/"),

];
