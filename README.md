# UTMKL RIDE 🚕

A web-based ride-booking system specifically designed for UTMKL students in the Kuala Lumpur area. This system allows students to easily book a ride, while providing the driver with a comprehensive admin dashboard to manage bookings in real-time.

## 🌟 Key Features

### For Students (Client-side)
* **Simple Booking Form:** Easy-to-use interface for booking rides (Pick-up, Drop-off, Date, and Time).
* **Driver Status Check:** If the driver is off-duty, the form automatically locks and displays a maintenance/off-duty screen.
* **Duplicate Prevention:** Prevents users from accidentally submitting duplicate bookings for the same date and time.
* **Instant PDF Receipt:** Auto-generates a downloadable PDF receipt containing ride details and driver information (Car model, Plate number).

### For Drivers (Admin Dashboard)
* **Real-time Notifications:** Auto-refreshes every 5 seconds. Plays a notification sound and displays a visual toast/desktop notification when a new booking arrives.
* **System Toggle (ON/OFF):** A single button to shut down the booking system (Off Duty) or open it up for students.
* **WhatsApp Integration:** 1-click buttons to WhatsApp the customer for booking confirmation or to request a cancellation/rearrangement with pre-filled message templates.
* **Booking Management:** Easily mark rides as 'Done' (Selesai) or 'Cancel' (Batal) to archive them.
* **Telegram Alerts:** Instantly sends a detailed message to the driver's Telegram whenever a new booking is submitted.

## 🛠️ Tech Stack
* **Frontend:** HTML5, CSS3, JavaScript
* **Backend:** PHP
* **Database:** MySQL
* **Libraries:** [html2pdf.js](https://github.com/eKoopmans/html2pdf.js) (for PDF generation)
* **Hosting:** Hosted via InfinityFree

## ⚙️ Setup & Installation

1. **Clone the repository:**
   ```bash
   git clone [https://github.com/muhammadsyamilhaikal/utmklride.git](https://github.com/muhammadsyamilhaikal/utmklride.git)

   SQL SETUP
   
   CREATE TABLE system_status (
    id INT PRIMARY KEY, 
    status VARCHAR(10));
    CREATE TABLE tempahan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100),
    telefon VARCHAR(20),
    pickup VARCHAR(255),
    dropoff VARCHAR(255),
    tarikh DATE,
    masa TIME,
    status VARCHAR(20) DEFAULT 'pending',
    created_at DATETIME);
