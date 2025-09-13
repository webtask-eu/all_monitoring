"""
User and subscription management for SS.lv Monitor
"""
import logging
from typing import List, Optional, Dict, Any
from datetime import datetime, timedelta
from .database_manager import DatabaseManager
from .models import SubscriptionModel

logger = logging.getLogger(__name__)

class UserManager:
    """User and subscription management"""
    
    def __init__(self, db_manager: DatabaseManager):
        self.db_manager = db_manager
    
    def create_user_subscription(self, user_id: str, url: str, frequency: str = '1h') -> bool:
        """Create user subscription"""
        try:
            # Parse URL to extract category and city
            category, city = self._parse_url(url)
            if not category or not city:
                return False
            
            # Check if subscription already exists
            existing = self.get_user_subscriptions(user_id)
            for sub in existing:
                if sub.url == url:
                    return True  # Already exists
            
            # Create new subscription
            subscription = SubscriptionModel(
                user_id=user_id,
                category=category,
                city=city,
                url=url,
                frequency=frequency,
                is_active=True
            )
            
            self.db_manager.save_subscription(subscription)
            logger.info(f"Created subscription for user {user_id}: {url}")
            return True
            
        except Exception as e:
            logger.error(f"Error creating subscription for user {user_id}: {e}")
            return False
    
    def get_user_subscriptions(self, user_id: str) -> List[SubscriptionModel]:
        """Get user subscriptions"""
        try:
            return self.db_manager.get_subscriptions(user_id=user_id, active_only=True)
        except Exception as e:
            logger.error(f"Error getting subscriptions for user {user_id}: {e}")
            return []
    
    def delete_user_subscription(self, user_id: str, subscription_id: int) -> bool:
        """Delete user subscription"""
        try:
            # Get subscription
            subscriptions = self.get_user_subscriptions(user_id)
            subscription = next((s for s in subscriptions if s.id == subscription_id), None)
            
            if not subscription:
                return False
            
            # Deactivate subscription
            subscription.is_active = False
            subscription.updated_at = datetime.now()
            
            # Update in database
            self.db_manager.update_subscription(subscription)
            logger.info(f"Deleted subscription {subscription_id} for user {user_id}")
            return True
            
        except Exception as e:
            logger.error(f"Error deleting subscription {subscription_id} for user {user_id}: {e}")
            return False
    
    def update_user_frequency(self, user_id: str, frequency: str) -> bool:
        """Update user's default frequency"""
        try:
            # Update all user subscriptions
            subscriptions = self.get_user_subscriptions(user_id)
            for subscription in subscriptions:
                subscription.frequency = frequency
                subscription.updated_at = datetime.now()
                self.db_manager.update_subscription(subscription)
            
            logger.info(f"Updated frequency to {frequency} for user {user_id}")
            return True
            
        except Exception as e:
            logger.error(f"Error updating frequency for user {user_id}: {e}")
            return False
    
    def get_users_for_scanning(self) -> List[Dict[str, Any]]:
        """Get all users with active subscriptions for scanning"""
        try:
            # Get all active subscriptions
            all_subscriptions = self.db_manager.get_subscriptions(active_only=True)
            
            # Group by user
            users = {}
            for sub in all_subscriptions:
                if sub.user_id not in users:
                    users[sub.user_id] = {
                        'user_id': sub.user_id,
                        'subscriptions': [],
                        'frequency': sub.frequency
                    }
                users[sub.user_id]['subscriptions'].append(sub)
            
            return list(users.values())
            
        except Exception as e:
            logger.error(f"Error getting users for scanning: {e}")
            return []
    
    def _parse_url(self, url: str) -> tuple[Optional[str], Optional[str]]:
        """Parse URL to extract category and city"""
        try:
            # Parse ss.lv URL structure
            # https://www.ss.lv/lv/real-estate/flats/riga/
            parts = url.split('/')
            
            if len(parts) < 6:
                return None, None
            
            # Find real-estate index
            try:
                re_index = parts.index('real-estate')
            except ValueError:
                return None, None
            
            if re_index + 2 >= len(parts):
                return None, None
            
            category = parts[re_index + 1]  # flats, homes-summer-residences
            city = parts[re_index + 2]      # riga, jurmala, etc.
            
            # Map categories
            category_map = {
                'flats': 'apartment',
                'homes-summer-residences': 'house'
            }
            
            mapped_category = category_map.get(category, category)
            
            return mapped_category, city
            
        except Exception as e:
            logger.error(f"Error parsing URL {url}: {e}")
            return None, None
    
    def get_user_stats(self, user_id: str) -> Dict[str, Any]:
        """Get user statistics"""
        try:
            subscriptions = self.get_user_subscriptions(user_id)
            
            return {
                'user_id': user_id,
                'total_subscriptions': len(subscriptions),
                'categories': list(set(sub.category for sub in subscriptions)),
                'cities': list(set(sub.city for sub in subscriptions)),
                'frequency': subscriptions[0].frequency if subscriptions else '1h',
                'last_updated': max(sub.updated_at for sub in subscriptions) if subscriptions else None
            }
            
        except Exception as e:
            logger.error(f"Error getting stats for user {user_id}: {e}")
            return {
                'user_id': user_id,
                'total_subscriptions': 0,
                'categories': [],
                'cities': [],
                'frequency': '1h',
                'last_updated': None
            }
