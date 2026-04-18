# Smart Water Distribution System (SWDS Meru)

A full-stack IoT platform for real-time water distribution monitoring and control across multiple zones in Meru County, Kenya.

This system integrates embedded hardware, wireless communication, machine learning and a web-based management dashboard to improve reliability, monitoring and decision-making in water infrastructure.

---

## Project Overview

The Smart Water Distribution System was designed to monitor water flow, detect anomalies and allow remote control of valves in a distributed network.

The system supports:

- Real-time sensor monitoring
- Remote valve control
- Demand forecasting
- Anomaly detection
- Resident complaint reporting
- Operator dashboards

---

## System Architecture

The system is structured into four layers:

Layer 4 — User Layer  
Admin, Operator and Resident dashboards

Layer 3 — Server Layer  
PHP backend, MySQL database, Python machine learning engine and REST API

Layer 2 — Communication Layer  
ESP32 master node, LoRa communication and WiFi connection to server

Layer 1 — Field Layer  
Sensor nodes, flow meters and valve control relays

---

## Technologies Used

Hardware:

- ESP32 microcontrollers
- LoRa SX1276 radio modules
- Flow sensors (YF-S201)
- Relay modules

Software:

- Python
- PHP
- MySQL
- JavaScript
- REST API
- Arduino C++

Libraries and Tools:

- scikit-learn
- pandas
- numpy
- Chart.js
- XAMPP

---

## Machine Learning Components

The system includes a predictive analytics module for demand forecasting and anomaly detection.

Models used:

- Random Forest regression for 7-day demand prediction
- Isolation Forest for anomaly detection

Additional statistical checks:

- Z-score detection
- Interquartile Range (IQR)
- Threshold validation

---

## Key Features

- Real-time water flow monitoring
- Automated anomaly detection
- Remote valve control
- Multi-role authentication system
- Data visualization dashboard
- Sensor network communication using LoRa

---

## System Performance

Sensor readings processed:

10,800+

Anomalies detected:

1,070

Forecast accuracy:

78%

Average command latency:

~12 seconds

Number of monitoring zones:

5

---

## Screenshots

Add screenshots here.

Example:

Dashboard interface  
Sensor monitoring page  
System architecture diagram  
Database schema  
Hardware setup

---

## Repository Structure

smart-water-system/

api/  
database/  
hardware/  
ml/  
web/  
README.md  

---

## Future Improvements

- SMS alert integration
- Mobile application interface
- Cloud deployment
- Predictive maintenance analytics
- Real-time leak detection

---

## Author

Kelvin Mwangi  
Bachelor of Science in Mathematics and Physics  
Meru university of science and technology
Kenya
