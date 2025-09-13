"""
Configuration management for SS.lv Monitor
"""
import os
from typing import Optional
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

class Config:
    """Configuration class for SS.lv Monitor"""
    
    # Application Info
    __version__: str = "2.2.0"
    __author__: str = "IntellaraX"
    __website__: str = "IntellaraX.com"
    
    # Telegram Bot Configuration
    TELEGRAM_BOT_TOKEN: str = os.getenv('TELEGRAM_BOT_TOKEN') or '8348868901:AAFaXAgENEALsh0qRLWnb_b8pJearMYdEmo'
    TELEGRAM_ADMIN_ID: str = os.getenv('TELEGRAM_ADMIN_ID') or '380740159'
    
    # Database Configuration
    DATABASE_PATH: str = os.getenv('DATABASE_PATH', 'ss_monitor.db')
    
    # Parsing Configuration
    MAX_PAGES_PER_CATEGORY: int = int(os.getenv('MAX_PAGES_PER_CATEGORY', '3'))
    REQUEST_TIMEOUT: int = int(os.getenv('REQUEST_TIMEOUT', '30'))
    REQUEST_DELAY: float = float(os.getenv('REQUEST_DELAY', '1.0'))
    
    # Notification Configuration
    ENABLE_NOTIFICATIONS: bool = os.getenv('ENABLE_NOTIFICATIONS', 'true').lower() == 'true'
    NOTIFICATION_COOLDOWN: int = int(os.getenv('NOTIFICATION_COOLDOWN', '300'))  # 5 minutes
    
    # Logging Configuration
    LOG_LEVEL: str = os.getenv('LOG_LEVEL', 'INFO')
    LOG_FILE: str = os.getenv('LOG_FILE', 'ss_monitor.log')
    
    # SS.lv URLs
    SS_BASE_URL: str = "https://www.ss.lv"
    SS_REAL_ESTATE_URL: str = f"{SS_BASE_URL}/lv/real-estate/"
    SS_FLATS_URL: str = f"{SS_BASE_URL}/lv/real-estate/flats/"
    SS_HOUSES_URL: str = f"{SS_BASE_URL}/lv/real-estate/homes-summer-residences/"
    
    # Supported categories
    SUPPORTED_CATEGORIES = {
        'apartment': {
            'name': 'Квартиры',
            'url_template': f"{SS_FLATS_URL}{{city}}/all/"
        },
        'house': {
            'name': 'Дома',
            'url_template': f"{SS_HOUSES_URL}{{city}}/all/"
        }
    }
    
    # Supported cities
    SUPPORTED_CITIES = {
        'riga': 'Рига',
        'jurmala': 'Юрмала',
        'liepaja': 'Лиепая',
        'daugavpils': 'Даугавпилс'
    }
    
    @classmethod
    def validate_config(cls) -> bool:
        """Validate configuration"""
        if not cls.TELEGRAM_BOT_TOKEN:
            raise ValueError("TELEGRAM_BOT_TOKEN is required")
        if not cls.TELEGRAM_ADMIN_ID:
            raise ValueError("TELEGRAM_ADMIN_ID is required")
        return True
    
    @classmethod
    def get_category_url(cls, category: str, city: str) -> Optional[str]:
        """Get URL for specific category and city"""
        if category not in cls.SUPPORTED_CATEGORIES:
            return None
        if city not in cls.SUPPORTED_CITIES:
            return None
        
        template = cls.SUPPORTED_CATEGORIES[category]['url_template']
        return template.format(city=city)

# Create global config instance
config = Config()
