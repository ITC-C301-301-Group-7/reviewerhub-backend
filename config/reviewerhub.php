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

    /*
    | Leaderboard. "timezone" decides when "This Month" / "This Semester" start (the app itself
    | stores dates in UTC). A semester runs from its start month until the next one starts; the
    | latest start on or before today is the current semester. Edit these to match the school calendar.
    */
    "leaderboard" => [
        "timezone" => env("LEADERBOARD_TIMEZONE", "Asia/Manila"),
        "points_per_upload" => 50,
        "points_per_five_star" => 10,
        "semesters" => [
            ["start_month" => 1, "label" => "2nd Semester"],
            ["start_month" => 6, "label" => "Summer"],
            ["start_month" => 8, "label" => "1st Semester"],
        ],
    ],

];
