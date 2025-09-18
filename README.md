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

1. First use the code in pinescript.txt copy the code (as it is beacuse its like python need indentation) and add to chart in tradingview.
	
	we can find PINE EDITOR in left bottom of tradingview.

	it looks like this: ![PINE EDITOR](images/pinescript.png)

Now save the script and click "ADD TO CHART"
![PINE EDITOR](images/pinescript_save.png)

	

it looks like this: ![PINE EDITOR](images/alerts_icon.png)


2. Set up database connection (`db.php`)
   	- add server deatils and bot token and admin chat id 


3. Run `setup.php` to Setup tables and admin login.
   	- this will create all required tables which are required for functioning.


4. use TRADINGVIEW webhookURL and set "yourwebsite.site/receiver.php" 

setup webhook : ![Webhook Setup](images/webhook.png)


5. Alerts are sent via `tg.php` or `webhook.php`

6. Admin dashboard available via `admin_dashboard.php`

## Screenshots
IMAGE-1 ![WORKING IMAGE1](images/1.png)


IMAGE- BOT COMMAND LIST ![WORKING IMAGE2](images/2.png)


TRADES LIST PDF  ![WORKING IMAGE3](images/3.png)


LIVE CHART IMAGE ![LIVE CHART IMAGE ](images/LIVECHART.png)


BACKEND SQL OVERVIEW ![DATABASE](images/SQL.png)


ADMIN DASHBOARD ![ADMIN_DASHBOARD](images/ADMIN_DASHBOARD.png)


TRADE FTECH PAGE ![TRADE FETCH](images/trade_fetch.png)


NEWS EVENTS PAGE ![NEWS EVENTS](images/NEWS.png)

