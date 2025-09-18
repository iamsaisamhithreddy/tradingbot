# Trading Bot

This repository is created for binary trading in 5 minutes time frame, this has many advanced features with web panels.

This requires "TRADINGVIEW API" so which we can get trail for 30 days. 

Run the specific pinescipt when an alert triggers it send POST request as JSON.

## Features
- Sends alerts directly to your telegram account. 
- Admin panel with advanced features like :
  	- Add News data
  	- Send Broadcast mesaages to users 
	- Trade reports (PDF)
   	- Tables backup
   	- Trade Enquiry
   	- Live prices
	- Telegram alerts
	- User management
 	- Webhook to telegram using API
    - GENERATE TOKENS
    - SESSION INFO 
- when an unauthorised user uses the bot it will be logged and sent to admin.
- from bot itself we can fetch many features such as trade list, session info.

## How it works
1. Set up database connection (`db.php`)
   	- add server deatils and bot token and admin chat id 

2. Run `setup.php` to Setup tables and admin login.
   	- this will create all required tables which are required for functioning.

3. use TRADINGVIEW webhookURL and set "yourwebsite.site/receiver.php" 
![Dashboard](images/webhook.png)

4. Alerts are sent via `tg.php` or `webhook.php`
5. Admin dashboard available via `admin_dashboard.php`

## Screenshots
![WORKING IMAGE1](images/1.png)
![WORKING IMAGE2](images/2.png)
