"""
SS.lv Parser - Improved version with better error handling and structure
"""
import re
import time
import logging
from typing import List, Optional, Dict, Any
from urllib.parse import urljoin
from bs4 import BeautifulSoup
import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

from ..config import config
from .models import Advertisement, PriceInfo

logger = logging.getLogger(__name__)

class SSParser:
    """SS.lv parser with improved error handling and retry logic"""
    
    def __init__(self):
        self.session = self._create_session()
        self.base_url = config.SS_BASE_URL
        
    def _create_session(self) -> requests.Session:
        """Create HTTP session with retry logic"""
        session = requests.Session()
        
        # Configure retry strategy
        retry_strategy = Retry(
            total=3,
            backoff_factor=1,
            status_forcelist=[429, 500, 502, 503, 504],
        )
        
        adapter = HTTPAdapter(max_retries=retry_strategy)
        session.mount("http://", adapter)
        session.mount("https://", adapter)
        
        # Set headers
        session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.5',
            'Accept-Encoding': 'gzip, deflate',
            'Connection': 'keep-alive',
        })
        
        return session
    
    def _make_request(self, url: str) -> Optional[requests.Response]:
        """Make HTTP request with error handling"""
        try:
            logger.debug(f"Making request to: {url}")
            response = self.session.get(
                url, 
                timeout=config.REQUEST_TIMEOUT,
                allow_redirects=True
            )
            response.raise_for_status()
            return response
        except requests.exceptions.RequestException as e:
            logger.error(f"Request failed for {url}: {e}")
            return None
    
    def _parse_price(self, price_text: str) -> PriceInfo:
        """Parse price and currency from text"""
        if not price_text:
            return PriceInfo(price=None, currency=None)
        
        price_text = re.sub(r'\s+', ' ', price_text.strip())
        
        # Check for monthly rent
        is_monthly = '/mēn.' in price_text or '/мес.' in price_text
        
        # Extract price and currency
        price_match = re.search(r'([\d\s,]+)\s*€(?:\/mēn\.)?', price_text)
        if price_match:
            price_str = price_match.group(1).replace(' ', '').replace(',', '')
            try:
                price = float(price_str)
                return PriceInfo(price=price, currency='EUR', is_monthly=is_monthly)
            except ValueError:
                pass
        
        # Fallback: extract any numbers
        numbers = re.findall(r'\d+', price_text)
        if numbers:
            try:
                price = float(''.join(numbers))
                return PriceInfo(price=price, currency='EUR', is_monthly=is_monthly)
            except ValueError:
                pass
        
        return PriceInfo(price=None, currency=None)
    
    def _extract_ss_id(self, url: str) -> Optional[str]:
        """Extract SS.lv ID from URL"""
        try:
            match = re.search(r'/([^/]+)\.html$', url)
            if match:
                return match.group(1)
            return None
        except Exception as e:
            logger.error(f"Error extracting SS ID from {url}: {e}")
            return None
    
    def _parse_advertisement(self, ad_element: BeautifulSoup, base_url: str) -> Optional[Advertisement]:
        """Parse single advertisement element"""
        try:
            tr_id = ad_element.get('id', '')
            if not tr_id.startswith('tr_'):
                return None
            
            ss_id = tr_id.replace('tr_', '')
            
            # Extract title and URL
            title_link = ad_element.find('a', class_='am')
            if not title_link:
                return None
            
            title = title_link.get_text(strip=True)
            relative_url = title_link.get('href')
            if not relative_url:
                return None
            
            full_url = urljoin(base_url, relative_url)
            
            # Extract data from cells
            cells = ad_element.find_all('td', class_='msga2-o')
            if len(cells) < 4:
                return None
            
            # Parse location (usually first cell)
            location = cells[0].get_text(strip=True) if cells[0] else None
            
            # Parse rooms (usually second cell)
            rooms_text = cells[1].get_text(strip=True) if len(cells) > 1 else ''
            rooms = None
            if rooms_text and rooms_text.isdigit():
                rooms = int(rooms_text)
            
            # Parse area (usually third cell)
            area_text = cells[2].get_text(strip=True) if len(cells) > 2 else ''
            area = None
            if area_text:
                area_match = re.search(r'(\d+(?:\.\d+)?)', area_text)
                if area_match:
                    area = float(area_match.group(1))
            
            # Parse floor (usually fourth cell)
            floor_text = cells[3].get_text(strip=True) if len(cells) > 3 else ''
            floor = None
            total_floors = None
            if floor_text:
                floor_match = re.search(r'(\d+)/(\d+)', floor_text)
                if floor_match:
                    floor = int(floor_match.group(1))
                    total_floors = int(floor_match.group(2))
                elif floor_text.isdigit():
                    floor = int(floor_text)
            
            # Parse price (usually last cell)
            price_text = cells[-1].get_text(strip=True) if cells else ''
            price_info = self._parse_price(price_text)
            
            # Determine property type
            property_type = 'apartment' if 'kv' in title.lower() or 'dzīvoklis' in title.lower() else 'house'
            
            # Extract image URL
            image_element = ad_element.find('img')
            image_url = None
            if image_element:
                image_src = image_element.get('src')
                if image_src:
                    image_url = urljoin(base_url, image_src)
            
            return Advertisement(
                ss_id=ss_id,
                title=title,
                url=full_url,
                price_info=price_info,
                location=location,
                area=area,
                rooms=rooms,
                floor=floor,
                total_floors=total_floors,
                property_type=property_type,
                image_url=image_url,
                description=title
            )
            
        except Exception as e:
            logger.error(f"Error parsing advertisement: {e}")
            return None
    
    def get_real_estate_ads(self, url: str, property_type: str = 'apartment', max_pages: int = None) -> List[Advertisement]:
        """Get real estate advertisements from URL"""
        if max_pages is None:
            max_pages = config.MAX_PAGES_PER_CATEGORY
        
        # Add /all/sell/ to URL for better results (only for sale ads)
        if not url.endswith('/all/sell/'):
            target_url = url.rstrip('/') + '/all/sell/'
            logger.info(f"Using URL with /all/sell/: {target_url}")
        else:
            target_url = url
        
        all_ads = []
        
        for page in range(1, max_pages + 1):
            try:
                page_url = f"{target_url}?page={page}" if page > 1 else target_url
                logger.info(f"Fetching ads from: {page_url}")
                
                response = self._make_request(page_url)
                if not response:
                    continue
                
                soup = BeautifulSoup(response.content, 'html.parser')
                
                # Find advertisement table
                main_table = soup.find('table', id='page_main')
                if not main_table:
                    logger.warning(f"No main table found on page {page}")
                    continue
                
                # Find advertisement rows
                ad_rows = main_table.find_all('tr', id=re.compile(r'^tr_\d+'))
                logger.info(f"Found {len(ad_rows)} advertisement rows on page {page}")
                
                # Parse each advertisement
                for row in ad_rows:
                    ad = self._parse_advertisement(row, self.base_url)
                    if ad:
                        all_ads.append(ad)
                
                # Add delay between requests
                if page < max_pages:
                    time.sleep(config.REQUEST_DELAY)
                    
            except Exception as e:
                logger.error(f"Error fetching page {page}: {e}")
                continue
        
        logger.info(f"Total advertisements collected: {len(all_ads)}")
        return all_ads
    
    def get_advertisement_details(self, url: str) -> Optional[Dict[str, Any]]:
        """Get detailed information about a specific advertisement"""
        try:
            response = self._make_request(url)
            if not response:
                return None
            
            soup = BeautifulSoup(response.content, 'html.parser')
            
            # Extract detailed information
            details = {
                'url': url,
                'ss_id': self._extract_ss_id(url),
                'title': '',
                'description': '',
                'price_info': PriceInfo(price=None, currency=None),
                'images': [],
                'features': {}
            }
            
            # Extract title
            title_element = soup.find('h1', class_='headtitle')
            if title_element:
                details['title'] = title_element.get_text(strip=True)
            
            # Extract description
            desc_element = soup.find('div', class_='msg_div_msg')
            if desc_element:
                details['description'] = desc_element.get_text(strip=True)
            
            # Extract price
            price_element = soup.find('span', class_='price')
            if price_element:
                price_text = price_element.get_text(strip=True)
                details['price_info'] = self._parse_price(price_text)
            
            # Extract images
            img_elements = soup.find_all('img', class_='pic')
            for img in img_elements:
                src = img.get('src')
                if src:
                    details['images'].append(urljoin(self.base_url, src))
            
            return details
            
        except Exception as e:
            logger.error(f"Error getting advertisement details from {url}: {e}")
            return None
