"""
Notification System for SS.lv Monitor - Refactored version
"""
import asyncio
import logging
from typing import List, Dict, Any, Optional
from datetime import datetime, timedelta

from ..config import config
from ..database import DatabaseManager
from ..parser import SSParser, Advertisement
from ..database.models import AdvertisementModel, PriceHistoryModel, SubscriptionModel

logger = logging.getLogger(__name__)

class NotificationSystem:
    """Notification system with improved error handling and structure"""
    
    def __init__(self, db_manager: DatabaseManager, bot=None):
        self.db_manager = db_manager
        self.parser = SSParser()
        self.bot = bot
        self.last_scan_time = None
    
    async def scan_and_notify(self) -> Dict[str, Any]:
        """Scan for new ads and send notifications"""
        start_time = datetime.now()
        
        try:
            logger.info("Starting scan and notification process")
            
            # Get current advertisements
            current_ads = await self._get_current_advertisements()
            logger.info(f"Retrieved {len(current_ads)} current advertisements")
            
            # Process new advertisements
            new_ads_result = await self._process_new_advertisements(current_ads)
            
            # Process price changes
            price_changes_result = await self._process_price_changes(current_ads)
            
            # Send notifications
            notifications_result = await self._send_notifications(new_ads_result, price_changes_result)
            
            # Update last scan time
            self.last_scan_time = start_time
            
            # Prepare result
            result = {
                'total_ads_scanned': len(current_ads),
                'new_ads_found': len(new_ads_result.get('new_ads', [])),
                'price_changes': len(price_changes_result.get('price_changes', [])),
                'notifications_sent': notifications_result.get('notifications_sent', 0),
                'execution_time': (datetime.now() - start_time).total_seconds()
            }
            
            logger.info(f"Scan completed: {result}")
            return result
            
        except Exception as e:
            logger.error(f"Error in scan and notification process: {e}")
            return {
                'total_ads_scanned': 0,
                'new_ads_found': 0,
                'price_changes': 0,
                'notifications_sent': 0,
                'execution_time': (datetime.now() - start_time).total_seconds(),
                'error': str(e)
            }
    
    async def _get_current_advertisements(self) -> List[Advertisement]:
        """Get current advertisements from all categories"""
        all_ads = []
        
        for category in config.SUPPORTED_CATEGORIES.keys():
            try:
                ads = self.parser.get_real_estate_ads(
                    category=category,
                    city='riga',  # Default to Riga for now
                    max_pages=config.MAX_PAGES_PER_CATEGORY
                )
                all_ads.extend(ads)
                logger.info(f"Found {len(ads)} {category} advertisements")
            except Exception as e:
                logger.error(f"Error fetching {category} advertisements: {e}")
                continue
        
        return all_ads
    
    async def _process_new_advertisements(self, current_ads: List[Advertisement]) -> Dict[str, Any]:
        """Process new advertisements"""
        new_ads = []
        
        for ad in current_ads:
            try:
                # Check if advertisement exists in database
                existing_ad = self.db_manager.get_advertisement_by_ss_id(ad.ss_id)
                
                if not existing_ad:
                    # New advertisement
                    logger.info(f"New advertisement: {ad.ss_id} - {ad.title}")
                    
                    # Convert to database model and save
                    ad_model = self._advertisement_to_model(ad)
                    ad_id = self.db_manager.save_advertisement(ad_model)
                    
                    # Save price history
                    if ad.price_info.price:
                        price_history = PriceHistoryModel(
                            advertisement_id=ad_id,
                            old_price=None,
                            new_price=ad.price_info.price,
                            currency=ad.price_info.currency,
                            change_type='new_ad'
                        )
                        self.db_manager.save_price_history(price_history)
                    
                    new_ads.append(ad)
                
            except Exception as e:
                logger.error(f"Error processing advertisement {ad.ss_id}: {e}")
                continue
        
        return {'new_ads': new_ads}
    
    async def _process_price_changes(self, current_ads: List[Advertisement]) -> Dict[str, Any]:
        """Process price changes"""
        price_changes = []
        
        for ad in current_ads:
            try:
                # Get existing advertisement
                existing_ad = self.db_manager.get_advertisement_by_ss_id(ad.ss_id)
                
                if existing_ad and existing_ad.price != ad.price_info.price:
                    # Price changed
                    logger.info(f"Price change detected: {ad.ss_id} - {existing_ad.price} -> {ad.price_info.price}")
                    
                    # Save price history
                    price_history = PriceHistoryModel(
                        advertisement_id=existing_ad.id,
                        old_price=existing_ad.price,
                        new_price=ad.price_info.price,
                        currency=ad.price_info.currency,
                        change_type='price_change'
                    )
                    self.db_manager.save_price_history(price_history)
                    
                    # Update advertisement
                    ad_model = self._advertisement_to_model(ad)
                    ad_model.id = existing_ad.id
                    self.db_manager.save_advertisement(ad_model)
                    
                    price_changes.append({
                        'advertisement': ad,
                        'old_price': existing_ad.price,
                        'new_price': ad.price_info.price,
                        'currency': ad.price_info.currency
                    })
                
            except Exception as e:
                logger.error(f"Error processing price change for {ad.ss_id}: {e}")
                continue
        
        return {'price_changes': price_changes}
    
    async def _send_notifications(self, new_ads_result: Dict[str, Any], price_changes_result: Dict[str, Any]) -> Dict[str, Any]:
        """Send notifications to users"""
        if not self.bot:
            logger.warning("No bot instance available for sending notifications")
            return {'notifications_sent': 0}
        
        notifications_sent = 0
        
        try:
            # Get all active subscriptions
            subscriptions = self.db_manager.get_subscriptions(active_only=True)
            logger.info(f"Processing {len(new_ads_result.get('new_ads', []))} new ads against {len(subscriptions)} subscriptions")
            
            # Send new advertisement notifications
            for ad in new_ads_result.get('new_ads', []):
                for subscription in subscriptions:
                    if self._matches_subscription(ad, subscription):
                        message = self._format_new_ad_message(ad)
                        await self.bot.send_message(subscription.user_id, message)
                        notifications_sent += 1
            
            # Send price change notifications
            for change in price_changes_result.get('price_changes', []):
                for subscription in subscriptions:
                    if self._matches_subscription(change['advertisement'], subscription):
                        message = self._format_price_change_message(change)
                        await self.bot.send_message(subscription.user_id, message)
                        notifications_sent += 1
            
            logger.info(f"Sent {notifications_sent} notifications")
            
        except Exception as e:
            logger.error(f"Error sending notifications: {e}")
        
        return {'notifications_sent': notifications_sent}
    
    def _matches_subscription(self, ad: Advertisement, subscription: SubscriptionModel) -> bool:
        """Check if advertisement matches subscription criteria"""
        try:
            # Check category
            if subscription.category and ad.property_type != subscription.category:
                return False
            
            # Check price range
            if ad.price_info.price:
                if subscription.min_price and ad.price_info.price < subscription.min_price:
                    return False
                if subscription.max_price and ad.price_info.price > subscription.max_price:
                    return False
            
            # Check area range
            if ad.area:
                if subscription.min_area and ad.area < subscription.min_area:
                    return False
                if subscription.max_area and ad.area > subscription.max_area:
                    return False
            
            # Check rooms range
            if ad.rooms:
                if subscription.min_rooms and ad.rooms < subscription.min_rooms:
                    return False
                if subscription.max_rooms and ad.rooms > subscription.max_rooms:
                    return False
            
            return True
            
        except Exception as e:
            logger.error(f"Error checking subscription match: {e}")
            return False
    
    def _format_new_ad_message(self, ad: Advertisement) -> str:
        """Format new advertisement message"""
        try:
            message = f"🏠 Новое объявление!\n\n"
            message += f"📝 {ad.title}\n"
            message += f"💰 Цена: {ad.price_info.price:,.0f} {ad.price_info.currency}\n"
            
            if ad.location:
                message += f"📍 Локация: {ad.location}\n"
            if ad.area:
                message += f"📐 Площадь: {ad.area} м²\n"
            if ad.rooms:
                message += f"🏠 Комнат: {ad.rooms}\n"
            if ad.floor and ad.total_floors:
                message += f"🏢 Этаж: {ad.floor}/{ad.total_floors}\n"
            
            message += f"\n🔗 {ad.url}"
            
            return message
            
        except Exception as e:
            logger.error(f"Error formatting new ad message: {e}")
            return f"🏠 Новое объявление: {ad.title}\n🔗 {ad.url}"
    
    def _format_price_change_message(self, change: Dict[str, Any]) -> str:
        """Format price change message"""
        try:
            ad = change['advertisement']
            old_price = change['old_price']
            new_price = change['new_price']
            currency = change['currency']
            
            price_diff = new_price - old_price
            price_diff_percent = (price_diff / old_price) * 100
            
            if price_diff > 0:
                change_emoji = "📈"
                change_text = f"+{price_diff:,.0f} {currency} (+{price_diff_percent:.1f}%)"
            else:
                change_emoji = "📉"
                change_text = f"{price_diff:,.0f} {currency} ({price_diff_percent:.1f}%)"
            
            message = f"{change_emoji} Изменение цены!\n\n"
            message += f"📝 {ad.title}\n"
            message += f"💰 Было: {old_price:,.0f} {currency}\n"
            message += f"💰 Стало: {new_price:,.0f} {currency}\n"
            message += f"📊 Изменение: {change_text}\n"
            
            if ad.location:
                message += f"📍 Локация: {ad.location}\n"
            if ad.area:
                message += f"📐 Площадь: {ad.area} м²\n"
            if ad.rooms:
                message += f"🏠 Комнат: {ad.rooms}\n"
            
            message += f"\n🔗 {ad.url}"
            
            return message
            
        except Exception as e:
            logger.error(f"Error formatting price change message: {e}")
            return f"📈 Изменение цены: {change['advertisement'].title}\n🔗 {change['advertisement'].url}"
    
    def _advertisement_to_model(self, ad: Advertisement) -> AdvertisementModel:
        """Convert Advertisement to AdvertisementModel"""
        return AdvertisementModel(
            ss_id=ad.ss_id,
            title=ad.title,
            url=ad.url,
            price=ad.price_info.price,
            currency=ad.price_info.currency,
            price_per_sqm=ad.price_info.price_per_sqm,
            is_monthly=ad.price_info.is_monthly,
            location=ad.location,
            area=ad.area,
            rooms=ad.rooms,
            floor=ad.floor,
            total_floors=ad.total_floors,
            property_type=ad.property_type,
            image_url=ad.image_url,
            description=ad.description
        )
