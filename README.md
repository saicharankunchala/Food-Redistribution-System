# Geo-Fenced Real-Time Food Redistribution System

This project is a working demo built from the PowerPoint requirements. It includes:

- User registration and login for `Donor` and `Receiver`
- Donor food posting with type, quantity, location, expiry time, and notes
- Geo-fenced receiver search using latitude, longitude, and radius
- Intelligent matching using `distance + time left + quantity`
- Real-time style notifications using auto-refresh polling
- Receiver request flow
- Donor accept or reject flow
- Status tracking: `Available`, `Requested`, `Accepted`, `Completed`, `Expired`
- Automatic expiry handling

## Project Structure

- `index.php` - main application with backend logic and UI
- `assets/styles.css` - responsive UI styling
- `assets/app.js` - notification polling and browser geolocation helper
- `storage/` - JSON files created automatically for demo data
- `database/schema.sql` - optional MySQL schema if you later want to move to XAMPP + MySQL

## Simple Run Method

This project is designed for an easy demo. It does **not** require MySQL to run.

### Option 1: Run with XAMPP

1. Install XAMPP if it is not already installed.
2. Copy this project folder into `htdocs`, for example:
   `C:\xampp\htdocs\myminiproject`
3. Start `Apache` from the XAMPP Control Panel.
4. Open:
   `http://localhost/myminiproject/index.php`

### Option 2: Run with PHP built-in server

1. Make sure PHP is installed.
2. Open terminal in the project folder.
3. Run:

```bash
php -S localhost:8000
```

4. Open:
   `http://localhost:8000/index.php`

## Faculty Demo Guide

Follow this exact order during the approval demo:

1. Open the home page.
2. Click `Load Demo Data`.
3. Log in as donor:
   `donor@example.com`
   `demo123`
4. Show the donor dashboard.
5. Create one new food listing with a nearby Hyderabad location and a future expiry time.
6. Open another browser or incognito window.
7. Log in as receiver:
   `receiver@example.com`
   `demo123`
8. Show the receiver location settings and save them if needed.
9. Show that only nearby listings appear.
10. Explain the intelligent matching score using:
    `distance + time left + quantity`
11. Request a food listing from the receiver account.
12. Switch back to donor and show the notification update.
13. Accept the request.
14. Switch back to receiver and show the accepted status notification.
15. Mark the pickup as completed from donor or receiver side.
16. Explain that expired items are auto-marked by the system.

## How to Demonstrate Each Module

### 1. Registration and Login

- Register a new user as donor or receiver
- Show role-based dashboard access

### 2. Donor Posting

- Enter title, food type, quantity, coordinates, address, and expiry time
- Publish listing

### 3. Geo-Fencing

- Save receiver coordinates and radius
- Show that nearby items are displayed and far items are filtered

### 4. Intelligent Matching

- Mention that every visible listing is scored using:
  - distance score
  - time urgency score
  - quantity score

### 5. Real-Time Notifications

- Keep both windows open
- Notifications refresh automatically every 10 seconds

### 6. Request Workflow

- Receiver selects listing and requests quantity
- Donor sees the incoming request

### 7. Approval Workflow

- Donor accepts or rejects request
- Receiver sees the resulting update

### 8. Status Tracking and Expiry

- Explain status progression:
  `Available -> Requested -> Accepted -> Completed`
- If time expires before completion:
  `Expired`

## Important Notes

- Use valid latitude and longitude values for better demo results.
- The project stores data in JSON files for easy execution and presentation.
- The included `database/schema.sql` shows how the same system can be moved to MySQL later.
- The browser location button helps fill receiver coordinates quickly.

## Suggested Demo Coordinates

Use these if you want smooth sample results:

- Donor location: `17.385000`, `78.486700`
- Receiver location: `17.406500`, `78.477200`
- Radius: `15`

## Validation Checklist

Before showing faculty, verify these:

1. Home page loads
2. Demo data loads
3. Donor login works
4. Receiver login works
5. New listing can be created
6. Receiver can see nearby listing
7. Receiver can request food
8. Donor can accept request
9. Completed status can be shown
10. Notifications panel updates correctly

## Future Upgrade Path

If faculty asks for expansion, you can say the next upgrade steps are:

- Replace JSON storage with MySQL using `database/schema.sql`
- Add admin dashboard
- Add map integration
- Add SMS or email notifications
- Add analytics and reporting
