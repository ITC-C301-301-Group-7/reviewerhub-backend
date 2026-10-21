# JRU Rizalian ReviewerHub — Backend

A Laravel REST API for JRU Rizalian ReviewerHub, a web platform where students and teachers share, review and manage learning resources (reviewers).

## Features

- **Accounts:** student and teacher registration, login with email or student/faculty ID, API tokens (Laravel Sanctum), logout with token revocation, profile photo upload.
- **Learning resources:** upload PDF, Word, PowerPoint and image files; private storage; authenticated preview and download.
- **Teacher tools:** dashboard statistics, manage and edit every reviewer, hide or restore reviewers, view student submissions, monitor community members.
- **Resource interaction:** bookmarks, 1–5 star ratings and comments.
- **QR sharing:** `GET /api/resources/{id}` powers QR codes and shared links.
- **Document translation:** translates text that the React app extracts (OCR for scans and photos happens in the browser).
- **Class schedule:** each student keeps a personal class schedule (add, edit, delete).

## Roles

| Action | Student | Teacher |
|---|---|---|
| Upload, preview, download reviewers | Yes | Yes |
| Delete reviewers | Own uploads only | Any upload |
| Edit details, hide or restore reviewers | No | Yes |
| Bookmark, rate (not your own), comment | Yes | Yes |
| Delete comments | Own comments | Any comment |
| Teacher dashboard (`/api/teacher/*`) | No (403) | Yes |
| Personal class schedule | Yes | Yes |

Hidden reviewers disappear from the community list. Only their owner and teachers can still open them.

## Technologies

Laravel, PHP, MySQL (SQLite also works), Laravel Sanctum, Composer. The frontend is a separate React app.

## Requirements

- PHP 8.2 or newer and Composer
- MySQL (XAMPP is fine) or SQLite
- Git
- Internet access on the server if you use document translation

## Installation

### 1. Get the code and install dependencies

```bash
git clone YOUR-BACKEND-REPOSITORY-URL
cd reviewerhub-backend
composer install
```

### 2. Configure the environment

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

### 3. Configure the database

`.env.example` defaults to SQLite. For MySQL, create a database named `reviewerhub` in phpMyAdmin and set:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=reviewerhub
DB_USERNAME=root
DB_PASSWORD=
```

### 4. Create the tables and sample data

```bash
php artisan migrate
php artisan db:seed
php artisan storage:link
```

`php artisan db:seed` runs three steps, and is safe to run again:

1. **TeacherSeeder:** creates the test teacher account (see below).
2. A sample student, `test@example.com`, created once.
3. **ExistingReviewersSeeder:** imports the PDFs found in `storage/app/private/resources` and copies any PDFs from `storage/app/public/resources` into that folder first. Imported reviewers belong to the teacher account.

`storage:link` is needed so profile pictures can be displayed.

### 5. Start the server

```bash
php artisan serve
```

The API runs at `http://127.0.0.1:8000`.

## Test teacher account

| Field | Value |
|---|---|
| Role | Faculty (teacher) |
| Faculty ID | `99-000001` |
| Email | `teacher@jru.edu.ph` |
| Password | `Teacher123` |

Run only this seeder with `php artisan db:seed --class=TeacherSeeder`. It is skipped when `APP_ENV=production`, so the known password never reaches a live server.

## Translation provider

The server translates text in pieces of about 450 characters and caches results for 7 days. Configure it in `.env`:

```env
TRANSLATION_PROVIDER=mymemory      # free, no account, small daily quota
MYMEMORY_EMAIL=you@example.com     # optional: raises the free quota
# For heavy use:
# TRANSLATION_PROVIDER=libretranslate
# LIBRETRANSLATE_URL=http://localhost:5000
# LIBRETRANSLATE_API_KEY=
```

## API Endpoints

Base URL: `http://127.0.0.1:8000/api`. "Auth" means a Bearer token is required.

### Accounts

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| POST | `/register` | Register a student or teacher | No |
| POST | `/login` | Log in | No |
| GET | `/user` | The signed-in user | Yes |
| POST | `/logout` | Revoke the current token | Yes |
| POST | `/profile/photo` | Upload a profile picture (`profile_photo`: JPG, PNG or WEBP, max 2 MB) | Yes |

### Learning resources

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| GET | `/resources` | List visible reviewers. Filters: `search`, `college`, `page`, `per_page`. Includes `ratingAverage`, `ratingCount`, `commentCount`, and `bookmarked` / `myRating` when a token is sent | No |
| POST | `/resources` | Upload (`title`, `course_code`, `college`, `description`, `file`). Allowed: pdf, doc, docx, ppt, pptx, jpg, jpeg, png, webp, up to 20 MB | Yes |
| GET | `/resources/{id}` | One resource with rating and bookmark info (used by QR links) | Yes |
| GET | `/resources/{id}/preview` | Inline view (PDF and images only) | Yes |
| GET | `/resources/{id}/download` | Download the file | Yes |
| DELETE | `/resources/{id}` | Delete (owner, or any teacher) | Yes |

### Bookmarks, ratings, comments

| Method | Endpoint | Description |
|---|---|---|
| GET | `/bookmarks` | The signed-in user's saved reviewers |
| PUT / DELETE | `/resources/{id}/bookmark` | Save / unsave |
| PUT | `/resources/{id}/rating` | `{ "rating": 1-5 }`. You cannot rate your own upload |
| GET / POST | `/resources/{id}/comments` | List / post (`{ "body": "..." }`, max 1000 characters) |
| DELETE | `/comments/{id}` | Delete a comment (its author or any teacher) |

All require authentication.

### Class schedule

| Method | Endpoint | Description |
|---|---|---|
| GET | `/schedule` | The signed-in user's classes |
| POST | `/schedule` | Add a class: `code`, `name`, `schedule` required; `room`, `instructor`, `grade` optional. Up to 30 classes per user |
| PUT | `/schedule/{id}` | Edit one of your classes |
| DELETE | `/schedule/{id}` | Remove one of your classes |

All require authentication. You can only see and change your own classes.

### Translation

| Method | Endpoint | Description |
|---|---|---|
| POST | `/translate` | `{ "text", "source", "target" }`, up to 3000 characters per request. Language codes such as `en`, `tl`, `es`, `zh-CN`. Source and target must differ |

Requires authentication.

### Teacher only

All routes below return 403 for non-teacher accounts.

| Method | Endpoint | Description |
|---|---|---|
| GET | `/teacher/overview` | Counts, 7-day upload activity, top contributors, recent uploads |
| GET | `/teacher/resources` | All reviewers including hidden. Filters: `author` (all, student, mine), `status` (all, approved, hidden), `search`, `page` |
| PATCH | `/teacher/resources/{id}` | Edit title, course code, college, description |
| PATCH | `/teacher/resources/{id}/status` | `status`: `approved` or `hidden`, plus an optional `note` |
| GET | `/teacher/community` | Members with upload counts. Filters: `role`, `search`, `page` |

## Request examples

Login:

```json
{
  "identifier": "student@example.com",
  "password": "example-password",
  "role": "student"
}
```

`identifier` accepts an email address or the student/faculty ID for the selected role.

Registration:

```json
{
  "name": "Juan Dela Cruz",
  "email": "student@example.com",
  "password": "example-password",
  "password_confirmation": "example-password",
  "role": "student",
  "student_number": "25-260000",
  "faculty_id": null,
  "department": "Information Technology",
  "year_level": "3rd Year"
}
```

For a teacher, use `"role": "teacher"` and provide a `faculty_id`.

## Frontend integration

The React app runs separately (default `http://localhost:5173`) and calls this API. Set `VITE_API_URL` in the frontend `.env` to this server's address. Configure Laravel CORS if the frontend runs on a different origin.

## Troubleshooting

- **A new route or class is "not found":** run `php artisan route:clear` and `php artisan config:clear`, then restart `php artisan serve`.
- **Preview says "The reviewer file could not be found":** the PDF is missing from `storage/app/private/resources`. Run `php artisan db:seed --class=ExistingReviewersSeeder`.
- **Translation says the daily quota is used up:** set `MYMEMORY_EMAIL` in `.env`, or switch to LibreTranslate.
- **Teacher login fails:** run `php artisan db:seed --class=TeacherSeeder` and check that `APP_ENV` is not `production`.
- **Check what was created:** `php artisan migrate:status`, and `php artisan route:list --path=api`.

## Security notes

- Never commit your `.env` file or any credentials.
- Reviewer files are stored on the private disk. They are only served through authenticated routes.
- Remove or change the test teacher account before deploying publicly.
- Use HTTPS when deploying.

---

**Project:** JRU Rizalian ReviewerHub  
**Component:** Laravel Backend API  
**Database:** MySQL or SQLite  
**Frontend:** React (Vite)