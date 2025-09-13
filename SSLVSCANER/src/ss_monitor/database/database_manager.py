"""
Database manager for SS.lv Monitor
"""
import sqlite3
import logging
from typing import List, Optional, Dict, Any
from datetime import datetime
from contextlib import contextmanager

from ..config import config
from .models import AdvertisementModel, PriceHistoryModel, SubscriptionModel

logger = logging.getLogger(__name__)

class DatabaseManager:
    """Database manager with improved error handling and connection management"""
    
    def __init__(self, db_path: str = None):
        self.db_path = db_path or config.DATABASE_PATH
        self._init_database()
    
    def _init_database(self):
        """Initialize database and create tables"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                
                # Create advertisements table
                cursor.execute('''
                    CREATE TABLE IF NOT EXISTS advertisements (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        ss_id TEXT UNIQUE NOT NULL,
                        title TEXT NOT NULL,
                        url TEXT NOT NULL,
                        price REAL,
                        currency TEXT,
                        price_per_sqm REAL,
                        is_monthly BOOLEAN DEFAULT 0,
                        location TEXT,
                        area REAL,
                        rooms INTEGER,
                        floor INTEGER,
                        total_floors INTEGER,
                        property_type TEXT,
                        image_url TEXT,
                        description TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        is_active BOOLEAN DEFAULT 1
                    )
                ''')
                
                # Create price history table
                cursor.execute('''
                    CREATE TABLE IF NOT EXISTS price_history (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        advertisement_id INTEGER NOT NULL,
                        old_price REAL,
                        new_price REAL,
                        currency TEXT,
                        change_type TEXT DEFAULT 'price_change',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (advertisement_id) REFERENCES advertisements (id)
                    )
                ''')
                
                # Create subscriptions table
                cursor.execute('''
                    CREATE TABLE IF NOT EXISTS subscriptions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id TEXT NOT NULL,
                        category TEXT NOT NULL,
                        city TEXT NOT NULL,
                        url TEXT NOT NULL,
                        frequency TEXT DEFAULT '1h',
                        min_price REAL,
                        max_price REAL,
                        min_area REAL,
                        max_area REAL,
                        min_rooms INTEGER,
                        max_rooms INTEGER,
                        is_active BOOLEAN DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ''')
                
                # Create indexes for better performance
                cursor.execute('CREATE INDEX IF NOT EXISTS idx_advertisements_ss_id ON advertisements(ss_id)')
                cursor.execute('CREATE INDEX IF NOT EXISTS idx_advertisements_property_type ON advertisements(property_type)')
                cursor.execute('CREATE INDEX IF NOT EXISTS idx_advertisements_is_active ON advertisements(is_active)')
                cursor.execute('CREATE INDEX IF NOT EXISTS idx_price_history_advertisement_id ON price_history(advertisement_id)')
                cursor.execute('CREATE INDEX IF NOT EXISTS idx_subscriptions_user_id ON subscriptions(user_id)')
                cursor.execute('CREATE INDEX IF NOT EXISTS idx_subscriptions_is_active ON subscriptions(is_active)')
                
                conn.commit()
                logger.info("Database initialized successfully")
                
        except Exception as e:
            logger.error(f"Error initializing database: {e}")
            raise
    
    @contextmanager
    def _get_connection(self):
        """Get database connection with proper error handling"""
        conn = None
        try:
            conn = sqlite3.connect(self.db_path)
            conn.row_factory = sqlite3.Row
            yield conn
        except Exception as e:
            if conn:
                conn.rollback()
            logger.error(f"Database connection error: {e}")
            raise
        finally:
            if conn:
                conn.close()
    
    def save_advertisement(self, ad: AdvertisementModel) -> int:
        """Save or update advertisement"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                
                # Check if advertisement exists
                cursor.execute('SELECT id FROM advertisements WHERE ss_id = ?', (ad.ss_id,))
                existing = cursor.fetchone()
                
                if existing:
                    # Update existing advertisement
                    cursor.execute('''
                        UPDATE advertisements SET
                            title = ?, url = ?, price = ?, currency = ?, price_per_sqm = ?,
                            is_monthly = ?, location = ?, area = ?, rooms = ?, floor = ?,
                            total_floors = ?, property_type = ?, image_url = ?, description = ?,
                            updated_at = CURRENT_TIMESTAMP, is_active = 1
                        WHERE ss_id = ?
                    ''', (
                        ad.title, ad.url, ad.price, ad.currency, ad.price_per_sqm,
                        ad.is_monthly, ad.location, ad.area, ad.rooms, ad.floor,
                        ad.total_floors, ad.property_type, ad.image_url, ad.description,
                        ad.ss_id
                    ))
                    ad_id = existing[0]
                else:
                    # Insert new advertisement
                    cursor.execute('''
                        INSERT INTO advertisements (
                            ss_id, title, url, price, currency, price_per_sqm, is_monthly,
                            location, area, rooms, floor, total_floors, property_type,
                            image_url, description
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ''', (
                        ad.ss_id, ad.title, ad.url, ad.price, ad.currency, ad.price_per_sqm,
                        ad.is_monthly, ad.location, ad.area, ad.rooms, ad.floor,
                        ad.total_floors, ad.property_type, ad.image_url, ad.description
                    ))
                    ad_id = cursor.lastrowid
                
                conn.commit()
                return ad_id
                
        except Exception as e:
            logger.error(f"Error saving advertisement {ad.ss_id}: {e}")
            raise
    
    def get_advertisement_by_ss_id(self, ss_id: str) -> Optional[AdvertisementModel]:
        """Get advertisement by SS ID"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute('SELECT * FROM advertisements WHERE ss_id = ?', (ss_id,))
                row = cursor.fetchone()
                
                if row:
                    return self._row_to_advertisement(row)
                return None
                
        except Exception as e:
            logger.error(f"Error getting advertisement {ss_id}: {e}")
            return None
    
    def get_all_advertisements(self, active_only: bool = True) -> List[AdvertisementModel]:
        """Get all advertisements"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                query = 'SELECT * FROM advertisements'
                if active_only:
                    query += ' WHERE is_active = 1'
                query += ' ORDER BY created_at DESC'
                
                cursor.execute(query)
                rows = cursor.fetchall()
                
                return [self._row_to_advertisement(row) for row in rows]
                
        except Exception as e:
            logger.error(f"Error getting advertisements: {e}")
            return []
    
    def save_price_history(self, price_history: PriceHistoryModel) -> int:
        """Save price history entry"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute('''
                    INSERT INTO price_history (
                        advertisement_id, old_price, new_price, currency, change_type
                    ) VALUES (?, ?, ?, ?, ?)
                ''', (
                    price_history.advertisement_id, price_history.old_price,
                    price_history.new_price, price_history.currency, price_history.change_type
                ))
                
                conn.commit()
                return cursor.lastrowid
                
        except Exception as e:
            logger.error(f"Error saving price history: {e}")
            raise
    
    def get_price_history(self, advertisement_id: int) -> List[PriceHistoryModel]:
        """Get price history for advertisement"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute('''
                    SELECT * FROM price_history 
                    WHERE advertisement_id = ? 
                    ORDER BY created_at DESC
                ''', (advertisement_id,))
                
                rows = cursor.fetchall()
                return [self._row_to_price_history(row) for row in rows]
                
        except Exception as e:
            logger.error(f"Error getting price history for {advertisement_id}: {e}")
            return []
    
    def save_subscription(self, subscription: SubscriptionModel) -> int:
        """Save subscription"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute('''
                    INSERT INTO subscriptions (
                        user_id, category, city, url, frequency, min_price, max_price, min_area, max_area,
                        min_rooms, max_rooms
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ''', (
                    subscription.user_id, subscription.category, subscription.city, subscription.url,
                    subscription.frequency, subscription.min_price, subscription.max_price, 
                    subscription.min_area, subscription.max_area, subscription.min_rooms, subscription.max_rooms
                ))
                
                conn.commit()
                return cursor.lastrowid
                
        except Exception as e:
            logger.error(f"Error saving subscription: {e}")
            raise
    
    def get_subscriptions(self, user_id: str = None, active_only: bool = True) -> List[SubscriptionModel]:
        """Get subscriptions"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                query = 'SELECT * FROM subscriptions'
                params = []
                
                conditions = []
                if user_id:
                    conditions.append('user_id = ?')
                    params.append(user_id)
                if active_only:
                    conditions.append('is_active = 1')
                
                if conditions:
                    query += ' WHERE ' + ' AND '.join(conditions)
                
                query += ' ORDER BY created_at DESC'
                
                cursor.execute(query, params)
                rows = cursor.fetchall()
                
                return [self._row_to_subscription(row) for row in rows]
                
        except Exception as e:
            logger.error(f"Error getting subscriptions: {e}")
            return []
    
    def update_subscription(self, subscription: SubscriptionModel) -> bool:
        """Update subscription"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute('''
                    UPDATE subscriptions SET
                        user_id = ?, category = ?, city = ?, url = ?, frequency = ?,
                        min_price = ?, max_price = ?, min_area = ?, max_area = ?,
                        min_rooms = ?, max_rooms = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ''', (
                    subscription.user_id, subscription.category, subscription.city, subscription.url,
                    subscription.frequency, subscription.min_price, subscription.max_price,
                    subscription.min_area, subscription.max_area, subscription.min_rooms,
                    subscription.max_rooms, subscription.is_active, subscription.id
                ))
                
                conn.commit()
                return cursor.rowcount > 0
                
        except Exception as e:
            logger.error(f"Error updating subscription: {e}")
            return False
    
    def get_total_ads_count(self) -> int:
        """Get total advertisements count"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute('SELECT COUNT(*) FROM advertisements WHERE is_active = 1')
                return cursor.fetchone()[0]
        except Exception as e:
            logger.error(f"Error getting ads count: {e}")
            return 0
    
    def get_total_subscriptions_count(self) -> int:
        """Get total subscriptions count"""
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute('SELECT COUNT(*) FROM subscriptions WHERE is_active = 1')
                return cursor.fetchone()[0]
        except Exception as e:
            logger.error(f"Error getting subscriptions count: {e}")
            return 0
    
    def _row_to_advertisement(self, row) -> AdvertisementModel:
        """Convert database row to AdvertisementModel"""
        return AdvertisementModel(
            id=row['id'],
            ss_id=row['ss_id'],
            title=row['title'],
            url=row['url'],
            price=row['price'],
            currency=row['currency'],
            price_per_sqm=row['price_per_sqm'],
            is_monthly=bool(row['is_monthly']),
            location=row['location'],
            area=row['area'],
            rooms=row['rooms'],
            floor=row['floor'],
            total_floors=row['total_floors'],
            property_type=row['property_type'],
            image_url=row['image_url'],
            description=row['description'],
            created_at=datetime.fromisoformat(row['created_at']) if row['created_at'] else None,
            updated_at=datetime.fromisoformat(row['updated_at']) if row['updated_at'] else None,
            is_active=bool(row['is_active'])
        )
    
    def _row_to_price_history(self, row) -> PriceHistoryModel:
        """Convert database row to PriceHistoryModel"""
        return PriceHistoryModel(
            id=row['id'],
            advertisement_id=row['advertisement_id'],
            old_price=row['old_price'],
            new_price=row['new_price'],
            currency=row['currency'],
            change_type=row['change_type'],
            created_at=datetime.fromisoformat(row['created_at']) if row['created_at'] else None
        )
    
    def _row_to_subscription(self, row) -> SubscriptionModel:
        """Convert database row to SubscriptionModel"""
        return SubscriptionModel(
            id=row['id'],
            user_id=row['user_id'],
            category=row['category'],
            city=row['city'],
            url=row['url'],
            frequency=row['frequency'],
            min_price=row['min_price'],
            max_price=row['max_price'],
            min_area=row['min_area'],
            max_area=row['max_area'],
            min_rooms=row['min_rooms'],
            max_rooms=row['max_rooms'],
            is_active=bool(row['is_active']),
            created_at=datetime.fromisoformat(row['created_at']) if row['created_at'] else None,
            updated_at=datetime.fromisoformat(row['updated_at']) if row['updated_at'] else None
        )
