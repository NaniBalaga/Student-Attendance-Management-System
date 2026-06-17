# 🎓 Attendance Calculator

A smart attendance management tool that helps students instantly calculate their attendance percentage, determine how many classes they need to attend to reach the required attendance criteria, and find out how many classes they can safely miss while maintaining the minimum attendance requirement.

Built with PHP, MySQL, HTML, CSS, and JavaScript, the application provides a clean and responsive interface with calculation history, attendance insights, and real-time status tracking.

---

## ✨ Features

* Calculate current attendance percentage
* Determine classes required to reach target attendance
* Calculate how many classes can be missed safely
* Custom attendance target support (75%, 80%, etc.)
* Automatic calculation history storage
* Personal usage statistics
* Attendance status indicators
* Interactive result modal
* Mobile-friendly responsive design
* Modern dark-themed user interface
* Smooth animations and visual feedback

---

## 📊 Attendance Calculation

The calculator accepts:

* Total Classes Conducted
* Classes Attended
* Required Attendance Percentage

Based on these values, the system calculates:

### Current Attendance

Current Attendance (%) =

(Attended Classes ÷ Total Classes) × 100

### Classes Needed

If attendance is below the required percentage, the calculator determines the minimum number of consecutive classes that must be attended to reach the target percentage.

### Classes Can Bunk

If attendance is above the required percentage, the calculator calculates the maximum number of classes that can be missed while still maintaining the required attendance.

---

## 🚀 How It Works

### Step 1

Enter the total number of classes conducted.

### Step 2

Enter the number of classes attended.

### Step 3

Enter the required attendance percentage.

### Step 4

Click **Calculate Attendance**.

### Step 5

The system displays:

* Current Attendance Percentage
* Classes Needed
* Classes Can Bunk
* Attendance Status

---

## 📈 Attendance Status

The calculator categorizes attendance into three levels:

### 🎉 On Track

Current attendance is equal to or above the required percentage.

### ⚠️ Close Call

Attendance is slightly below the target and requires attention.

### 🚨 Needs Attention

Attendance is significantly below the required percentage and immediate improvement is needed.

---

## 📜 Calculation History

Every calculation is stored automatically and includes:

* Date & Time
* Total Classes
* Attended Classes
* Required Percentage
* Current Attendance Percentage
* Classes Needed
* Classes Can Bunk

This helps students monitor their attendance progress over time.

---

## 📊 Statistics Dashboard

The system provides:

### Personal Statistics

* Total Calculations Performed
* Today's Calculations

### Global Statistics

* Total Registered Users
* Total Attendance Calculations

---

## 🛠️ Technologies Used

### Frontend

* HTML5
* CSS3
* JavaScript
* Font Awesome

### Backend

* PHP

### Database

* MySQL

---

## 🗄️ Database Tables

### attendance_history

Stores all attendance calculations.

| Column              | Description        |
| ------------------- | ------------------ |
| id                  | Record ID          |
| email               | User Email         |
| total_classes       | Total Classes      |
| attended_classes    | Attended Classes   |
| required_percentage | Target Attendance  |
| current_percentage  | Current Attendance |
| classes_needed      | Classes Required   |
| classes_can_bunk    | Allowed Bunks      |
| calculation_date    | Timestamp          |

### calculator_usage

Stores calculator usage analytics.

| Column     | Description     |
| ---------- | --------------- |
| id         | Record ID       |
| email      | User Email      |
| usage_time | Usage Timestamp |

---

## 🎯 Use Cases

* Attendance planning
* Exam eligibility tracking
* Safe bunk calculation
* Academic monitoring
* Attendance recovery planning

---

## 📱 Responsive Design

The calculator is optimized for:

* Mobile Devices
* Tablets
* Laptops
* Desktop Screens

---

## 👨‍💻 Author

**Nani Balaga**

Developed to help students easily manage attendance and stay above the required attendance threshold.

---

## ⭐ Support

If you find this project useful, consider giving it a star on GitHub and sharing it with your friends.
