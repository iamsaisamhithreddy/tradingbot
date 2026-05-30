# 🚀 Trading Bot — Advanced Binary Trading Automation Platform

An advanced TradingView-powered binary trading automation platform with Telegram integration, AI-assisted trade evaluation, admin dashboard, live alerts, economic news filtering, analytics, and user management.

Built primarily for **FOREX currency pairs** on the **5-minute timeframe**, with future expansion planned for:

* Crypto Markets
* Stock Markets
* AI-driven trade filtering

---

# ✨ Key Features

## 📡 TradingView Webhook Automation

* Real-time TradingView alert integration
* JSON-based webhook handling
* Multi-pair monitoring
* Automated signal processing
* 5-minute timeframe optimization

---

# 🤖 Telegram Bot Integration

## Telegram Features

* Instant trade alerts
* Price-nearby notifications
* Disappearing alert messages (auto-delete after 2 minutes)
* Unauthorized user detection
* Session information commands
* Trade history retrieval
* AI-integrated alerts
* Broadcast messaging system

### AI Telegram Integration

![AI Integration](images/ai_telegram.png)

---

# 🛠️ Advanced Admin Dashboard

## Dashboard Features

* User management
* Live market prices
* Session monitoring
* API key management
* Telegram alert management
* Trade enquiry handling
* Database backup tools
* Broadcast controls
* Trade analytics

---

## 📢 Broadcast Messaging

Send announcements and updates instantly to all users.

![Broadcast Messages](images/Broadcast.png)

---

## 🔑 API Manager

Manage AI/API keys securely from the dashboard.

![API Manager](images/Ai_Api_KeysManager.png)

---

## 📊 Auto Trade Evaluation

Automatically evaluate trades and performance metrics.

![Auto Trade Evaluation](images/AutoTrade_Evaluation.png)

---

## 📈 Trade Enquiry System

Track and manage user trade requests.

![Trade Enquery](images/Trade_Enquery.png)

---

# 🧠 AI Integration Features

* AI-assisted trade evaluation
* Smart filtering logic
* News-aware trade filtering
* Telegram AI integration
* Future AI learning system planned

---

# 🔐 Security Features

* Unauthorized access logging
* Admin alerts for suspicious activity
* Token-based authentication
* Webhook validation
* Session tracking

---

# ⏰ Automated Scheduling

Using cron jobs:

* Trade reports
* Economic news reports
* Scheduled broadcasts
* Session cleanup
* Automated analytics

![Auto Send](images/autosend.png)

---

# 🖥️ System Architecture

TradingView → Webhook → PHP Backend → Database → Telegram Bot → User Alerts

---

# ⚙️ Requirements

## Required

* TradingView account
* Telegram Bot
* PHP hosting/server
* MySQL database

## Recommended

* TradingView Premium/Pro Plan
* VPS hosting
* Cron job access

---

# 📥 Installation Guide

# Step 1 — Initial Setup

Complete the initial configuration and server setup.

![Initial Setup](images/InitialSetup.png)

---

# Step 2 — Create Telegram Bot

Open:

https://t.me/BotFather

Create a new bot and obtain:

* Bot Token
* Bot Username

Configure bot commands in BotFather.

---

# Step 3 — Configure Telegram Webhook

```bash
https://api.telegram.org/botBOT_TOKEN/setWebhook?url=https://YOUR_DOMAIN/webhook.php
```

---

# Step 4 — Add Pine Script to TradingView

Copy the code from:

```text
pinescript.txt
```

Open TradingView → Pine Editor → Paste Script.

Save and click:

```text
Add To Chart
```

![Pine Editor](images/pinescript.png)

![Save Pine Script](images/pinescript_save.png)

---

# Step 5 — Configure TradingView Alerts

Open Alerts Panel and create a new alert.

## Settings

| Setting   | Value                     |
| --------- | ------------------------- |
| Condition | Your Script               |
| Trigger   | Any alert() function call |
| Interval  | 5 Minutes                 |

![Alert Setup](images/alert_setup.png)

Click:

```text
Create
```

---

# Step 6 — Configure Database

Edit:

```text
db.php
```

Update:

* Database credentials
* Bot token
* Admin chat ID

---

# Step 7 — Run Setup Script

Execute:

```text
setup.php
```

This creates:

* Required tables
* Admin account
* System configuration

---

# Step 8 — Configure TradingView Webhook URL

Use:

```text
https://YOUR_DOMAIN/receiver.php
```

![Webhook Setup](images/webhook.png)

---

# Step 9 — Launch Admin Dashboard

Open:

```text
admin_dashboard.php
```

---

# Step 10 — Economic News Integration

Run:

```bash
python news.py
```

This scrapes economic calendar data from Investing.com and generates:

```text
economic_calendar.txt
```

⚠️ Run after **9:30 AM IST** because data refreshes based on GMT -4 timezone.

![News Scraping](images/news_scraping.png)

---

# Step 11 — Import Economic News Data

Upload generated news file from the dashboard.

Click:

```text
Process Data
```

This imports all events into the database.

![Add News](images/add_news.png)

---

# 📸 Screenshots

## 🤖 Bot Command List

![Bot Commands](images/2.png)

---

## 📊 Admin Dashboard

![Admin Dashboard](images/ADMIN_DASHBOARD.png)

---

## 📈 Trade Reports PDF

![Trade Reports](images/3.png)

---

## 📉 Live Trading Charts

![Live Charts](images/LIVECHART.png)

---

## 🗄️ Database Overview

![Database](images/SQL.png)

---

## 📰 News Events

![News Events](images/NEWS.png)

---

## 📡 Session Information

![Session Info](images/session_info.png)

---

# 📌 Current Market Support

✅ Forex
🚧 Crypto (Planned)
🚧 Stocks (Planned)

---

# 🔮 Future Roadmap

* AI-based trade filtering
* Multi-timeframe confirmations
* Risk management engine
* Strategy optimizer
* Copy trading support
* Mobile dashboard
* Docker deployment
* Cloud deployment
* Performance heatmaps
* Auto-learning signal engine
* Multi-user subscription model

---

# 🤝 Collaboration

Open to contributors and collaborations.

Areas actively being improved:

* AI integration
* Signal optimization
* UI/UX improvements
* Scalability
* Market expansion

---

# ⚠️ Disclaimer

This project is intended for educational and research purposes only.

Trading involves financial risk. Use responsibly.

---

# 📬 Contact

For collaboration, issues, or feature requests:

* Open an issue
* Submit a pull request
* Contribute improvements

---
