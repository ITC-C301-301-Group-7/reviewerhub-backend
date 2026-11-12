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

    /*
    | Contributor tiers (Tier 1 to Tier 10, every 100 points up to 1000). A student's ALL-TIME leaderboard points decide their tier, and the tier decides
    | how much of a reviewer they can preview and how many files they can download each month.
    |   min_points         all-time points needed for the tier
    |   preview_pages      PDF pages shown in Preview (null = the whole file)
    |   downloads_per_month  different reviewers they may download per month (0 = none, null = unlimited)
    | Everyone gets the free preview of the first pages. Owners and faculty always see everything.
    */
    "tiers" => [
        ["key" => "tier1", "name" => "Tier 1", "min_points" => 0, "preview_pages" => 3, "downloads_per_month" => 0],
        ["key" => "tier2", "name" => "Tier 2", "min_points" => 100, "preview_pages" => 5, "downloads_per_month" => 3],
        ["key" => "tier3", "name" => "Tier 3", "min_points" => 200, "preview_pages" => 8, "downloads_per_month" => 5],
        ["key" => "tier4", "name" => "Tier 4", "min_points" => 300, "preview_pages" => 12, "downloads_per_month" => 8],
        ["key" => "tier5", "name" => "Tier 5", "min_points" => 400, "preview_pages" => 16, "downloads_per_month" => 12],
        ["key" => "tier6", "name" => "Tier 6", "min_points" => 500, "preview_pages" => 20, "downloads_per_month" => 16],
        ["key" => "tier7", "name" => "Tier 7", "min_points" => 600, "preview_pages" => 30, "downloads_per_month" => 20],
        ["key" => "tier8", "name" => "Tier 8", "min_points" => 700, "preview_pages" => 40, "downloads_per_month" => 30],
        ["key" => "tier9", "name" => "Tier 9", "min_points" => 800, "preview_pages" => 60, "downloads_per_month" => 50],
        ["key" => "tier10", "name" => "Tier 10", "min_points" => 900, "preview_pages" => null, "downloads_per_month" => null],
    ],

];
