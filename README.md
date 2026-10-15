
# JRU Rizalian ReviewerHub — Backend

A Laravel REST API backend for JRU Rizalian ReviewerHub, a web-based platform designed to support students and teachers in accessing and sharing learning resources and reviewers.

## Technologies Used

- Laravel
- PHP
- MySQL
- Laravel Sanctum
- React.js (Frontend)
- Composer

## Features

- Student and teacher registration
- Login using email or student/faculty ID
- Password hashing and verification
- API token authentication using Laravel Sanctum
- Authenticated user information retrieval
- Logout and token revocation
- MySQL database integration

## Requirements

- PHP
- Composer
- MySQL or XAMPP
- Git

## Installation

### 1. Clone the Repository

```bash
git clone YOUR-BACKEND-REPOSITORY-URL
cd reviewerhub-backend
```

Replace `YOUR-BACKEND-REPOSITORY-URL` with your GitHub repository URL.

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure Environment

Create your local environment file:

```powershell
Copy-Item .env.example .env
```

Generate the application key:

```bash
php artisan key:generate
```

### 4. Configure the Database

Create a MySQL database named `reviewerhub` in phpMyAdmin.

Update your local `.env` file:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=reviewerhub
DB_USERNAME=root
DB_PASSWORD=
```

Change the username and password if your MySQL configuration is different.

### 5. Run Migrations

```bash
php artisan migrate
```

### 6. Start the Server

```bash
php artisan serve
```

The backend will usually run at:

http://127.0.0.1:8000

## API Endpoints

Base URL: `http://127.0.0.1:8000/api`

| Method | Endpoint | Description | Authentication |
|---|---|---|---|
| POST | `/register` | Register a student or teacher | No |
| POST | `/login` | Log in to an account | No |
| GET | `/user` | Retrieve the authenticated user | Required |
| POST | `/logout` | Log out and revoke the current token | Required |

## Login Request Example

```json
{
  "identifier": "student@example.com",
  "password": "example-password",
  "role": "student"
}
```

The `identifier` field accepts an email address or the appropriate student/faculty ID for the selected role.

## Registration Request Example

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

For teacher registration, use `"role": "teacher"` and provide a `faculty_id`.

## React Frontend Integration

The React frontend runs separately from the Laravel backend.

Example API endpoints:

- `http://127.0.0.1:8000/api/login`
- `http://127.0.0.1:8000/api/register`

Configure Laravel CORS if the frontend runs on a different origin.

## Security Notes

- Never upload your `.env` file to GitHub.
- Do not commit passwords, API secrets, or other credentials.
- Keep authentication tokens private.
- Use HTTPS when deploying publicly.

## Development Status

The backend currently provides the initial authentication API for student and teacher registration, login, retrieving the authenticated user, and logout.

Additional features, such as learning resource management and reviewer uploads, can be added as development continues.

## Project Purpose

JRU Rizalian ReviewerHub aims to provide a centralized platform for learning resources and reviewer sharing to support students and teachers in their academic activities.

---

**Project:** JRU Rizalian ReviewerHub  
**Component:** Laravel Backend API  
**Database:** MySQL  
**Frontend:** React.js
