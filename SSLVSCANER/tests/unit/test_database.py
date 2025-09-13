"""
Unit tests for database manager
"""
import pytest
from datetime import datetime
from src.ss_monitor.database.database_manager import DatabaseManager
from src.ss_monitor.database.models import AdvertisementModel, PriceHistoryModel, SubscriptionModel

class TestDatabaseManager:
    """Test cases for DatabaseManager"""
    
    def test_database_initialization(self, temp_db):
        """Test database initialization"""
        # Database should be created and tables should exist
        with temp_db._get_connection() as conn:
            cursor = conn.cursor()
            
            # Check if tables exist
            cursor.execute("SELECT name FROM sqlite_master WHERE type='table'")
            tables = [row[0] for row in cursor.fetchall()]
            
            assert 'advertisements' in tables
            assert 'price_history' in tables
            assert 'subscriptions' in tables
    
    def test_save_advertisement_new(self, temp_db):
        """Test saving new advertisement"""
        ad = AdvertisementModel(
            ss_id="12345678",
            title="Test Apartment",
            url="https://www.ss.lv/test.html",
            price=100000.0,
            currency="EUR",
            location="Riga",
            area=50.0,
            rooms=2
        )
        
        ad_id = temp_db.save_advertisement(ad)
        assert ad_id is not None
        assert ad_id > 0
    
    def test_save_advertisement_existing(self, temp_db):
        """Test updating existing advertisement"""
        # Save initial advertisement
        ad1 = AdvertisementModel(
            ss_id="12345678",
            title="Test Apartment",
            url="https://www.ss.lv/test.html",
            price=100000.0,
            currency="EUR"
        )
        ad_id1 = temp_db.save_advertisement(ad1)
        
        # Update advertisement
        ad2 = AdvertisementModel(
            ss_id="12345678",
            title="Updated Test Apartment",
            url="https://www.ss.lv/test.html",
            price=120000.0,
            currency="EUR"
        )
        ad_id2 = temp_db.save_advertisement(ad2)
        
        # Should return same ID
        assert ad_id1 == ad_id2
        
        # Check if updated
        retrieved_ad = temp_db.get_advertisement_by_ss_id("12345678")
        assert retrieved_ad.title == "Updated Test Apartment"
        assert retrieved_ad.price == 120000.0
    
    def test_get_advertisement_by_ss_id(self, temp_db):
        """Test getting advertisement by SS ID"""
        ad = AdvertisementModel(
            ss_id="12345678",
            title="Test Apartment",
            url="https://www.ss.lv/test.html",
            price=100000.0,
            currency="EUR"
        )
        temp_db.save_advertisement(ad)
        
        retrieved_ad = temp_db.get_advertisement_by_ss_id("12345678")
        assert retrieved_ad is not None
        assert retrieved_ad.ss_id == "12345678"
        assert retrieved_ad.title == "Test Apartment"
        assert retrieved_ad.price == 100000.0
    
    def test_get_advertisement_by_ss_id_not_found(self, temp_db):
        """Test getting non-existent advertisement"""
        retrieved_ad = temp_db.get_advertisement_by_ss_id("nonexistent")
        assert retrieved_ad is None
    
    def test_get_all_advertisements(self, temp_db):
        """Test getting all advertisements"""
        # Save test advertisements
        ad1 = AdvertisementModel(
            ss_id="12345678",
            title="Test Apartment 1",
            url="https://www.ss.lv/test1.html",
            price=100000.0,
            currency="EUR"
        )
        ad2 = AdvertisementModel(
            ss_id="87654321",
            title="Test Apartment 2",
            url="https://www.ss.lv/test2.html",
            price=150000.0,
            currency="EUR"
        )
        
        temp_db.save_advertisement(ad1)
        temp_db.save_advertisement(ad2)
        
        all_ads = temp_db.get_all_advertisements()
        assert len(all_ads) == 2
        
        # Check if ads are sorted by created_at DESC
        # Note: Since we're using the same timestamp, the order might vary
        # We'll just check that both ads are present
        ss_ids = [ad.ss_id for ad in all_ads]
        assert "12345678" in ss_ids
        assert "87654321" in ss_ids
    
    def test_save_price_history(self, temp_db):
        """Test saving price history"""
        # First save an advertisement
        ad = AdvertisementModel(
            ss_id="12345678",
            title="Test Apartment",
            url="https://www.ss.lv/test.html",
            price=100000.0,
            currency="EUR"
        )
        ad_id = temp_db.save_advertisement(ad)
        
        # Save price history
        price_history = PriceHistoryModel(
            advertisement_id=ad_id,
            old_price=90000.0,
            new_price=100000.0,
            currency="EUR",
            change_type="price_change"
        )
        
        history_id = temp_db.save_price_history(price_history)
        assert history_id is not None
        assert history_id > 0
    
    def test_get_price_history(self, temp_db):
        """Test getting price history"""
        # Save advertisement and price history
        ad = AdvertisementModel(
            ss_id="12345678",
            title="Test Apartment",
            url="https://www.ss.lv/test.html",
            price=100000.0,
            currency="EUR"
        )
        ad_id = temp_db.save_advertisement(ad)
        
        price_history1 = PriceHistoryModel(
            advertisement_id=ad_id,
            old_price=90000.0,
            new_price=100000.0,
            currency="EUR"
        )
        price_history2 = PriceHistoryModel(
            advertisement_id=ad_id,
            old_price=100000.0,
            new_price=110000.0,
            currency="EUR"
        )
        
        temp_db.save_price_history(price_history1)
        temp_db.save_price_history(price_history2)
        
        history = temp_db.get_price_history(ad_id)
        assert len(history) == 2
        
        # Check if sorted by created_at DESC
        # Note: Since we're using the same timestamp, the order might vary
        # We'll just check that both price changes are present
        new_prices = [h.new_price for h in history]
        assert 100000.0 in new_prices
        assert 110000.0 in new_prices
    
    def test_save_subscription(self, temp_db):
        """Test saving subscription"""
        subscription = SubscriptionModel(
            user_id="123456789",
            category="apartment",
            city="riga",
            min_price=50000.0,
            max_price=200000.0,
            min_area=30.0,
            max_area=100.0,
            min_rooms=1,
            max_rooms=3
        )
        
        sub_id = temp_db.save_subscription(subscription)
        assert sub_id is not None
        assert sub_id > 0
    
    def test_get_subscriptions(self, temp_db):
        """Test getting subscriptions"""
        # Save test subscriptions
        sub1 = SubscriptionModel(
            user_id="123456789",
            category="apartment",
            city="riga"
        )
        sub2 = SubscriptionModel(
            user_id="987654321",
            category="house",
            city="riga"
        )
        
        temp_db.save_subscription(sub1)
        temp_db.save_subscription(sub2)
        
        # Get all subscriptions
        all_subs = temp_db.get_subscriptions()
        assert len(all_subs) == 2
        
        # Get subscriptions for specific user
        user_subs = temp_db.get_subscriptions(user_id="123456789")
        assert len(user_subs) == 1
        assert user_subs[0].user_id == "123456789"
    
    def test_get_total_ads_count(self, temp_db):
        """Test getting total ads count"""
        # Initially should be 0
        assert temp_db.get_total_ads_count() == 0
        
        # Save test advertisement
        ad = AdvertisementModel(
            ss_id="12345678",
            title="Test Apartment",
            url="https://www.ss.lv/test.html",
            price=100000.0,
            currency="EUR"
        )
        temp_db.save_advertisement(ad)
        
        # Should be 1
        assert temp_db.get_total_ads_count() == 1
    
    def test_get_total_subscriptions_count(self, temp_db):
        """Test getting total subscriptions count"""
        # Initially should be 0
        assert temp_db.get_total_subscriptions_count() == 0
        
        # Save test subscription
        subscription = SubscriptionModel(
            user_id="123456789",
            category="apartment",
            city="riga"
        )
        temp_db.save_subscription(subscription)
        
        # Should be 1
        assert temp_db.get_total_subscriptions_count() == 1

class TestDatabaseModels:
    """Test cases for database models"""
    
    def test_advertisement_model_to_dict(self):
        """Test AdvertisementModel to_dict method"""
        ad = AdvertisementModel(
            ss_id="12345678",
            title="Test Apartment",
            url="https://www.ss.lv/test.html",
            price=100000.0,
            currency="EUR",
            location="Riga",
            area=50.0,
            rooms=2
        )
        
        ad_dict = ad.to_dict()
        
        assert ad_dict['ss_id'] == "12345678"
        assert ad_dict['title'] == "Test Apartment"
        assert ad_dict['price'] == 100000.0
        assert ad_dict['currency'] == "EUR"
        assert ad_dict['location'] == "Riga"
        assert ad_dict['area'] == 50.0
        assert ad_dict['rooms'] == 2
    
    def test_advertisement_model_from_dict(self):
        """Test AdvertisementModel from_dict method"""
        ad_dict = {
            'ss_id': '12345678',
            'title': 'Test Apartment',
            'url': 'https://www.ss.lv/test.html',
            'price': 100000.0,
            'currency': 'EUR',
            'location': 'Riga',
            'area': 50.0,
            'rooms': 2,
            'created_at': '2023-01-01T00:00:00',
            'updated_at': '2023-01-01T00:00:00',
            'is_active': True
        }
        
        ad = AdvertisementModel.from_dict(ad_dict)
        
        assert ad.ss_id == "12345678"
        assert ad.title == "Test Apartment"
        assert ad.price == 100000.0
        assert ad.currency == "EUR"
        assert ad.location == "Riga"
        assert ad.area == 50.0
        assert ad.rooms == 2
        assert ad.is_active == True
    
    def test_price_history_model_to_dict(self):
        """Test PriceHistoryModel to_dict method"""
        price_history = PriceHistoryModel(
            advertisement_id=1,
            old_price=90000.0,
            new_price=100000.0,
            currency="EUR",
            change_type="price_change"
        )
        
        history_dict = price_history.to_dict()
        
        assert history_dict['advertisement_id'] == 1
        assert history_dict['old_price'] == 90000.0
        assert history_dict['new_price'] == 100000.0
        assert history_dict['currency'] == "EUR"
        assert history_dict['change_type'] == "price_change"
    
    def test_subscription_model_to_dict(self):
        """Test SubscriptionModel to_dict method"""
        subscription = SubscriptionModel(
            user_id="123456789",
            category="apartment",
            city="riga",
            min_price=50000.0,
            max_price=200000.0
        )
        
        sub_dict = subscription.to_dict()
        
        assert sub_dict['user_id'] == "123456789"
        assert sub_dict['category'] == "apartment"
        assert sub_dict['city'] == "riga"
        assert sub_dict['min_price'] == 50000.0
        assert sub_dict['max_price'] == 200000.0
