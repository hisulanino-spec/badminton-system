# StoneBoysClub Badminton Tournament Bracket & Scoring System

A responsive, high-contrast Badminton Tournament Bracket and Scoring System designed for casual and barkada tournament organization.

## Features
- **Singles & Random Doubles formats** supported.
- **Double Elimination bracket generation** (Winner, Loser, and Grand Finals brackets).
- **Casual Play Scoring**: Single-set matches (Set 1 only) played up to **30 points** (win-by-2, capped at 40 points).
- **Admin Dashboard**: Easily manage tournaments, add/delete players, configure match scores, and track live standings.

---

## How to Set Up and Run Locally

### Prerequisites
- **XAMPP** (includes Apache and MySQL) or any PHP 8.x + MySQL local environment.

### 1. Project Location
Clone or place the repository directly into your XAMPP `htdocs` directory:
```bash
C:\xampp\htdocs\badmintonsytem
```

### 2. Database Setup
1. Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
2. Open your web browser and navigate to **phpMyAdmin**:
   ```
   http://localhost/phpmyadmin/
   ```
3. Create a new database named `badminton_bracket`.
4. Select the `badminton_bracket` database, click on the **Import** tab.
5. Click **Choose File**, select the `db.sql` file located in the root of this project, and click **Import** (or **Go**).

### 3. Connection Configuration
1. Navigate to the `config` directory in the project.
2. Duplicate or rename `database.php.example` to `database.php`.
3. Open `config/database.php` in a text editor and update your database credentials if different from the defaults:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_PORT', '3306');       // Update if your MySQL runs on a custom port
   define('DB_USER', 'root');       // Your database username
   define('DB_PASS', '');           // Your database password
   define('DB_NAME', 'badminton_bracket');
   ```

### 4. Running the Application
Once Apache and MySQL are running and the database is configured, open your browser and navigate to:

- **Public bracket view:**
  ```
  http://localhost/badmintonsytem/
  ```
- **Admin Management dashboard:**
  ```
  http://localhost/badmintonsytem/admin/
  ```

#### Default Admin Credentials:
- **Username:** `admin`
- **Password:** `admin123`
