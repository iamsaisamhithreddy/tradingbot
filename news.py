import requests
from bs4 import BeautifulSoup
import json
import time
from datetime import datetime, timedelta
import pytz

def get_economic_calendar():
    """
    Scrape economic calendar data from Investing.com and convert to IST
    """
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.5',
        'Connection': 'keep-alive',
    }
    
    try:
        print("Fetching economic calendar data...")
        
        # First get the main page to get cookies
        session = requests.Session()
        main_response = session.get('https://www.investing.com/economic-calendar/', headers=headers, timeout=10)
        
        if main_response.status_code != 200:
            print(f"Failed to access page. Status code: {main_response.status_code}")
            return []
        
        # Now try to get the calendar data
        calendar_url = 'https://www.investing.com/economic-calendar/Service/getCalendarFilteredData'
        
        # Use New York timezone (GMT-4 or GMT-5 depending on DST) as source
        ny_tz = pytz.timezone('America/New_York')
        now_ny = datetime.now(ny_tz)
        is_dst = now_ny.dst().total_seconds() != 0
        timezone_offset = 4 if is_dst else 5  # EDT is GMT-4, EST is GMT-5
        
        payload = {
            'country[]': ['all'],
            'importance[]': ['1', '2', '3'],
            'timeZone': str(timezone_offset),
            'timeFilter': 'timeRemain',
            'currentTab': 'today',
            'submitFilters': '1',
            'limit_from': '0'
        }
        
        headers['X-Requested-With'] = 'XMLHttpRequest'
        headers['Referer'] = 'https://www.investing.com/economic-calendar/'
        
        response = session.post(calendar_url, headers=headers, data=payload, timeout=10)
        
        if response.status_code == 200:
            try:
                data = json.loads(response.text)
                if 'data' in data:
                    return parse_calendar_data(data['data'])
                else:
                    print("No data found in response")
                    return []
            except json.JSONDecodeError:
                print("Failed to parse JSON response")
                return []
        else:
            print(f"API request failed. Status code: {response.status_code}")
            return []
            
    except Exception as e:
        print(f"Error: {e}")
        return []

def parse_calendar_data(html_content):
    """
    Parse the HTML content of the calendar and convert times to IST
    """
    soup = BeautifulSoup(html_content, 'lxml')
    events = []
    
    # Find all event rows
    rows = soup.find_all('tr', class_='js-event-item')
    
    # Timezone conversion setup
    ny_tz = pytz.timezone('America/New_York')
    ist_tz = pytz.timezone('Asia/Kolkata')
    
    for row in rows:
        try:
            # Extract time
            time_elem = row.find('td', class_='time')
            time_val = time_elem.get_text(strip=True) if time_elem else 'N/A'
            
            # Skip if time is not available
            if time_val == 'N/A' or time_val == 'All Day':
                continue
                
            # Convert time to IST
            try:
                # Parse the time (format is usually like "02:30")
                event_time_ny = datetime.strptime(time_val, "%H:%M")
                # Set the date to today
                today_ny = datetime.now(ny_tz)
                event_time_ny = event_time_ny.replace(
                    year=today_ny.year, 
                    month=today_ny.month, 
                    day=today_ny.day
                )
                # Localize and convert to IST
                event_time_ny = ny_tz.localize(event_time_ny)
                event_time_ist = event_time_ny.astimezone(ist_tz)
                time_val_ist = event_time_ist.strftime("%H:%M")
            except:
                time_val_ist = time_val + " (conversion failed)"
            
            # Extract currency
            currency_elem = row.find('td', class_='left')
            currency_val = currency_elem.get_text(strip=True) if currency_elem else 'N/A'
            
            # Extract impact (number of bull icons)
            impact_icons = row.find_all('i', class_='grayFullBullishIcon')
            impact_val = len(impact_icons)
            
            # Extract event name
            event_elem = row.find('td', class_='event')
            event_val = event_elem.get_text(strip=True) if event_elem else 'N/A'
            
            events.append({
                'time': time_val_ist,
                'currency': currency_val,
                'impact': impact_val,
                'event': event_val
            })
            
        except Exception as e:
            print(f"Error parsing row: {e}")
            continue
    
    return events

def print_calendar_data(events):
    """
    Print the calendar data in a formatted way
    """
    if not events:
        print("No events found!")
        return
    
    print(f"\n📅 Economic Calendar - Found {len(events)} events (IST Timezone)")
    print("=" * 80)
    
    for i, event in enumerate(events, 1):
        impact_stars = "★" * event['impact']
        print(f"{i:2d}. {event['time']:8} | {event['currency']:6} | Impact: {impact_stars}")
        print(f"    Event: {event['event']}")
        print("-" * 80)

# Alternative: Simple web scraping without API (more reliable)
def simple_scrape():
    """
    Simple direct HTML scraping approach with IST conversion
    """
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    }
    
    # Timezone conversion setup
    ny_tz = pytz.timezone('America/New_York')
    ist_tz = pytz.timezone('Asia/Kolkata')
    
    try:
        response = requests.get('https://www.investing.com/economic-calendar/', headers=headers, timeout=10)
        
        if response.status_code == 200:
            soup = BeautifulSoup(response.content, 'lxml')
            events = []
            
            # Look for calendar table
            table = soup.find('table', {'id': 'economicCalendarData'})
            if table:
                rows = table.find_all('tr', class_='js-event-item')
                
                for row in rows:
                    try:
                        cells = row.find_all('td')
                        if len(cells) >= 4:
                            time_val = cells[0].get_text(strip=True)
                            
                            # Skip if time is not available
                            if time_val == 'All Day':
                                continue
                                
                            # Convert time to IST
                            try:
                                # Parse the time (format is usually like "02:30")
                                event_time_ny = datetime.strptime(time_val, "%H:%M")
                                # Set the date to today
                                today_ny = datetime.now(ny_tz)
                                event_time_ny = event_time_ny.replace(
                                    year=today_ny.year, 
                                    month=today_ny.month, 
                                    day=today_ny.day
                                )
                                # Localize and convert to IST
                                event_time_ny = ny_tz.localize(event_time_ny)
                                event_time_ist = event_time_ny.astimezone(ist_tz)
                                time_val_ist = event_time_ist.strftime("%H:%M")
                            except:
                                time_val_ist = time_val + " (conversion failed)"
                            
                            currency_val = cells[1].get_text(strip=True)
                            impact_icons = row.find_all('i', class_='grayFullBullishIcon')
                            impact_val = len(impact_icons)
                            event_val = cells[3].get_text(strip=True)
                            
                            events.append({
                                'time': time_val_ist,
                                'currency': currency_val,
                                'impact': impact_val,
                                'event': event_val
                            })
                    except:
                        continue
            
            return events
        else:
            print(f"Failed to fetch page. Status code: {response.status_code}")
            return []
            
    except Exception as e:
        print(f"Error in simple scrape: {e}")
        return []

if __name__ == "__main__":
    print("🌍 Fetching Economic Calendar Data from Investing.com...")
    
    # Try the API method first
    calendar_data = get_economic_calendar()
    
    # If API method fails, try simple scraping
    if not calendar_data:
        print("API method failed, trying simple scraping...")
        calendar_data = simple_scrape()
    
    # Print results
    print_calendar_data(calendar_data)
    
    # Save to file
    if calendar_data:
        with open('economic_calendar.txt', 'w', encoding='utf-8') as f:
            f.write("Economic Calendar Data (IST Timezone)\n")
            f.write("=" * 50 + "\n")
            for event in calendar_data:
                f.write(f"Time: {event['time']} | Currency: {event['currency']} | Impact: {event['impact']}\n")
                f.write(f"Event: {event['event']}\n")
                f.write("-" * 50 + "\n")
        print("\n✅ Data saved to 'economic_calendar.txt'")