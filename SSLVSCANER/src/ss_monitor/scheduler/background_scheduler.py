"""
Background scheduler for SS.lv Monitor
"""
import asyncio
import logging
from typing import Dict, List, Any
from datetime import datetime, timedelta
from apscheduler.schedulers.asyncio import AsyncIOScheduler
from apscheduler.triggers.interval import IntervalTrigger

from ..config import config
from ..database import DatabaseManager
from ..database.user_manager import UserManager
from ..parser import SSParser
from ..notifications import NotificationSystem

logger = logging.getLogger(__name__)

class BackgroundScheduler:
    """Background scheduler for periodic scanning"""
    
    def __init__(self, db_manager: DatabaseManager = None, bot=None):
        self.db_manager = db_manager or DatabaseManager()
        self.user_manager = UserManager(self.db_manager)
        self.parser = SSParser()
        self.notification_system = NotificationSystem(self.db_manager, bot)
        self.scheduler = AsyncIOScheduler()
        self.scan_tasks = {}  # Track running scan tasks
        self._setup_scheduler()
    
    def _setup_scheduler(self):
        """Setup the scheduler"""
        try:
            # Add job for scanning all user subscriptions
            self.scheduler.add_job(
                self._scan_all_subscriptions,
                trigger=IntervalTrigger(minutes=30),  # Check every 30 minutes
                id='scan_all_subscriptions',
                name='Scan all user subscriptions',
                replace_existing=True
            )
            
            logger.info("Background scheduler setup completed")
            
        except Exception as e:
            logger.error(f"Error setting up scheduler: {e}")
            raise
    
    async def _scan_all_subscriptions(self):
        """Scan all user subscriptions"""
        try:
            logger.info("Starting background scan of all subscriptions")
            
            # Get all users with active subscriptions
            users = self.user_manager.get_users_for_scanning()
            
            if not users:
                logger.info("No active subscriptions found")
                return
            
            logger.info(f"Found {len(users)} users with active subscriptions")
            
            # Process each user's subscriptions
            for user_data in users:
                user_id = user_data['user_id']
                subscriptions = user_data['subscriptions']
                
                # Check if user needs scanning based on frequency
                if self._should_scan_user(user_data):
                    await self._scan_user_subscriptions(user_id, subscriptions)
                else:
                    logger.debug(f"User {user_id} not due for scanning yet")
            
            logger.info("Background scan completed")
            
        except Exception as e:
            logger.error(f"Error in background scan: {e}")
    
    def _should_scan_user(self, user_data: Dict[str, Any]) -> bool:
        """Check if user should be scanned based on frequency"""
        try:
            frequency = user_data.get('frequency', '1h')
            subscriptions = user_data.get('subscriptions', [])
            
            if not subscriptions:
                return False
            
            # Get the most recent scan time for this user
            last_scan = self._get_user_last_scan_time(user_data['user_id'])
            
            if not last_scan:
                return True  # First scan
            
            # Calculate next scan time based on frequency
            next_scan = self._calculate_next_scan_time(last_scan, frequency)
            
            return datetime.now() >= next_scan
            
        except Exception as e:
            logger.error(f"Error checking scan frequency for user: {e}")
            return False
    
    def _get_user_last_scan_time(self, user_id: str) -> datetime:
        """Get user's last scan time"""
        try:
            # This would be stored in a user_scan_logs table
            # For now, we'll use a simple approach
            return datetime.now() - timedelta(hours=2)  # Placeholder
        except Exception as e:
            logger.error(f"Error getting last scan time for user {user_id}: {e}")
            return None
    
    def _calculate_next_scan_time(self, last_scan: datetime, frequency: str) -> datetime:
        """Calculate next scan time based on frequency"""
        try:
            frequency_map = {
                '1h': timedelta(hours=1),
                '4h': timedelta(hours=4),
                '12h': timedelta(hours=12),
                '1d': timedelta(days=1)
            }
            
            interval = frequency_map.get(frequency, timedelta(hours=1))
            return last_scan + interval
            
        except Exception as e:
            logger.error(f"Error calculating next scan time: {e}")
            return datetime.now() + timedelta(hours=1)
    
    async def _scan_user_subscriptions(self, user_id: str, subscriptions: List[Any]):
        """Scan subscriptions for a specific user"""
        try:
            logger.info(f"Scanning subscriptions for user {user_id}")
            
            # Group subscriptions by URL to avoid duplicate scanning
            urls = list(set(sub.url for sub in subscriptions))
            
            for url in urls:
                try:
                    # Scan this URL
                    ads = await self._scan_url(url)
                    
                    if ads:
                        # Process new ads and price changes
                        await self._process_ads_for_user(user_id, ads, url)
                    
                except Exception as e:
                    logger.error(f"Error scanning URL {url} for user {user_id}: {e}")
            
            # Update user's last scan time
            self._update_user_scan_time(user_id)
            
        except Exception as e:
            logger.error(f"Error scanning subscriptions for user {user_id}: {e}")
    
    async def _scan_url(self, url: str) -> List[Any]:
        """Scan a specific URL"""
        try:
            # Add /all/sell/ to URL for better results (only for sale ads)
            if not url.endswith('/all/sell/'):
                target_url = url.rstrip('/') + '/all/sell/'
                logger.info(f"Scanning URL with /all/sell/: {target_url}")
            else:
                target_url = url
            
            # Use the parser to get ads from URL
            if 'flats' in url:
                ads = self.parser.get_real_estate_ads(target_url, 'apartment')
            elif 'homes-summer-residences' in url:
                ads = self.parser.get_real_estate_ads(target_url, 'house')
            else:
                ads = self.parser.get_real_estate_ads(target_url, 'apartment')
            
            return ads
            
        except Exception as e:
            logger.error(f"Error scanning URL {url}: {e}")
            return []
    
    async def _process_ads_for_user(self, user_id: str, ads: List[Any], url: str):
        """Process ads for a specific user"""
        try:
            # Get user's subscriptions for this URL
            subscriptions = self.user_manager.get_user_subscriptions(user_id)
            url_subscriptions = [sub for sub in subscriptions if sub.url == url]
            
            if not url_subscriptions:
                return
            
            # Process each ad
            for ad in ads:
                try:
                    # Save advertisement
                    ad_id = self.db_manager.save_advertisement(ad)
                    
                    # Check for new ads and price changes
                    await self.notification_system._process_advertisement(ad, user_id)
                    
                except Exception as e:
                    logger.error(f"Error processing ad for user {user_id}: {e}")
            
        except Exception as e:
            logger.error(f"Error processing ads for user {user_id}: {e}")
    
    def _update_user_scan_time(self, user_id: str):
        """Update user's last scan time"""
        try:
            # This would update a user_scan_logs table
            # For now, we'll just log it
            logger.info(f"Updated scan time for user {user_id}")
        except Exception as e:
            logger.error(f"Error updating scan time for user {user_id}: {e}")
    
    def start(self):
        """Start the scheduler"""
        try:
            self.scheduler.start()
            logger.info("Background scheduler started")
        except Exception as e:
            logger.error(f"Error starting scheduler: {e}")
            raise
    
    def stop(self):
        """Stop the scheduler"""
        try:
            self.scheduler.shutdown()
            logger.info("Background scheduler stopped")
        except Exception as e:
            logger.error(f"Error stopping scheduler: {e}")
    
    def add_user_scan_job(self, user_id: str, frequency: str):
        """Add a specific scan job for a user"""
        try:
            job_id = f"scan_user_{user_id}"
            
            # Calculate interval
            frequency_map = {
                '1h': 60,  # minutes
                '4h': 240,
                '12h': 720,
                '1d': 1440
            }
            
            interval_minutes = frequency_map.get(frequency, 60)
            
            # Add job
            self.scheduler.add_job(
                self._scan_user_subscriptions,
                trigger=IntervalTrigger(minutes=interval_minutes),
                id=job_id,
                name=f"Scan user {user_id} subscriptions",
                replace_existing=True,
                args=[user_id, []]
            )
            
            logger.info(f"Added scan job for user {user_id} with frequency {frequency}")
            
        except Exception as e:
            logger.error(f"Error adding scan job for user {user_id}: {e}")
    
    def remove_user_scan_job(self, user_id: str):
        """Remove scan job for a user"""
        try:
            job_id = f"scan_user_{user_id}"
            self.scheduler.remove_job(job_id)
            logger.info(f"Removed scan job for user {user_id}")
        except Exception as e:
            logger.error(f"Error removing scan job for user {user_id}: {e}")
    
    def get_scheduler_status(self) -> Dict[str, Any]:
        """Get scheduler status"""
        try:
            jobs = self.scheduler.get_jobs()
            
            return {
                'scheduler_running': self.scheduler.running,
                'total_jobs': len(jobs),
                'jobs': [
                    {
                        'id': job.id,
                        'name': job.name,
                        'next_run': job.next_run_time.isoformat() if job.next_run_time else None
                    }
                    for job in jobs
                ]
            }
            
        except Exception as e:
            logger.error(f"Error getting scheduler status: {e}")
            return {
                'scheduler_running': False,
                'total_jobs': 0,
                'jobs': []
            }
