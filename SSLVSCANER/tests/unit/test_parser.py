"""
Unit tests for SS.lv parser
"""
import pytest
from unittest.mock import Mock, patch
from bs4 import BeautifulSoup
from src.ss_monitor.parser.ss_parser import SSParser
from src.ss_monitor.parser.models import Advertisement, PriceInfo

class TestSSParser:
    """Test cases for SSParser"""
    
    def test_parse_price_euro(self):
        """Test parsing Euro prices"""
        parser = SSParser()
        
        # Test various price formats
        test_cases = [
            ("100,000 €", 100000.0, "EUR", False),
            ("850 €/mēn.", 850.0, "EUR", True),
            ("1 500 €", 1500.0, "EUR", False),
            ("50,000 €/мес.", 50000.0, "EUR", True),
            ("Invalid price", None, None, False),
            ("", None, None, False)
        ]
        
        for price_text, expected_price, expected_currency, expected_monthly in test_cases:
            price_info = parser._parse_price(price_text)
            assert price_info.price == expected_price
            assert price_info.currency == expected_currency
            assert price_info.is_monthly == expected_monthly
    
    def test_extract_ss_id(self):
        """Test SS ID extraction from URL"""
        parser = SSParser()
        
        test_cases = [
            ("https://www.ss.lv/lv/real-estate/flats/riga/test123.html", "test123"),
            ("https://www.ss.lv/lv/real-estate/houses/riga/abc456.html", "abc456"),
            ("https://www.ss.lv/invalid-url", None),
            ("", None)
        ]
        
        for url, expected_id in test_cases:
            result = parser._extract_ss_id(url)
            assert result == expected_id
    
    def test_parse_advertisement(self, sample_html):
        """Test advertisement parsing"""
        parser = SSParser()
        soup = BeautifulSoup(sample_html, 'html.parser')
        ad_row = soup.find('tr', id='tr_12345678')
        
        result = parser._parse_advertisement(ad_row, "https://www.ss.lv")
        
        assert result is not None
        assert result.ss_id == "12345678"
        assert result.title == "Test Apartment"
        assert result.location == "Riga"
        assert result.rooms == 2
        assert result.area == 50.0
        assert result.floor == 3
        assert result.total_floors == 5
        assert result.price_info.price == 100000.0
        assert result.price_info.currency == "EUR"
    
    def test_parse_advertisement_invalid_row(self):
        """Test parsing invalid advertisement row"""
        parser = SSParser()
        soup = BeautifulSoup("<tr><td>Invalid row</td></tr>", 'html.parser')
        ad_row = soup.find('tr')
        
        result = parser._parse_advertisement(ad_row, "https://www.ss.lv")
        assert result is None
    
    @patch('src.ss_monitor.parser.ss_parser.SSParser._make_request')
    def test_get_real_estate_ads_success(self, mock_request, sample_html):
        """Test successful real estate ads fetching"""
        parser = SSParser()
        
        # Mock response
        mock_response = Mock()
        mock_response.content = sample_html.encode()
        mock_request.return_value = mock_response
        
        # Test fetching
        ads = parser.get_real_estate_ads('apartment', 'riga', 1)
        
        assert len(ads) == 1
        assert ads[0].ss_id == "12345678"
        assert ads[0].title == "Test Apartment"
    
    @patch('src.ss_monitor.parser.ss_parser.SSParser._make_request')
    def test_get_real_estate_ads_failure(self, mock_request):
        """Test real estate ads fetching with network failure"""
        parser = SSParser()
        
        # Mock failed request
        mock_request.return_value = None
        
        ads = parser.get_real_estate_ads('apartment', 'riga', 1)
        assert len(ads) == 0
    
    def test_get_real_estate_ads_invalid_category(self):
        """Test fetching with invalid category"""
        parser = SSParser()
        
        ads = parser.get_real_estate_ads('invalid_category', 'riga', 1)
        assert len(ads) == 0
    
    def test_get_real_estate_ads_invalid_city(self):
        """Test fetching with invalid city"""
        parser = SSParser()
        
        ads = parser.get_real_estate_ads('apartment', 'invalid_city', 1)
        assert len(ads) == 0

class TestPriceInfo:
    """Test cases for PriceInfo model"""
    
    def test_price_info_validation(self):
        """Test PriceInfo validation"""
        # Valid price info
        price_info = PriceInfo(price=100000.0, currency='EUR')
        assert price_info.price == 100000.0
        assert price_info.currency == 'EUR'
        
        # Invalid price (negative)
        with pytest.raises(ValueError):
            PriceInfo(price=-1000.0, currency='EUR')
        
        # Invalid currency
        with pytest.raises(ValueError):
            PriceInfo(price=100000.0, currency='INVALID')

class TestAdvertisement:
    """Test cases for Advertisement model"""
    
    def test_advertisement_validation(self):
        """Test Advertisement validation"""
        price_info = PriceInfo(price=100000.0, currency='EUR')
        
        # Valid advertisement
        ad = Advertisement(
            ss_id="12345678",
            title="Test Apartment",
            url="https://www.ss.lv/test.html",
            price_info=price_info
        )
        assert ad.ss_id == "12345678"
        assert ad.title == "Test Apartment"
        
        # Invalid advertisement (missing required fields)
        with pytest.raises(ValueError):
            Advertisement(
                ss_id="",
                title="Test Apartment",
                url="https://www.ss.lv/test.html",
                price_info=price_info
            )
        
        # Invalid area
        with pytest.raises(ValueError):
            Advertisement(
                ss_id="12345678",
                title="Test Apartment",
                url="https://www.ss.lv/test.html",
                price_info=price_info,
                area=-50.0
            )
    
    def test_advertisement_to_dict(self):
        """Test Advertisement to_dict method"""
        price_info = PriceInfo(price=100000.0, currency='EUR')
        ad = Advertisement(
            ss_id="12345678",
            title="Test Apartment",
            url="https://www.ss.lv/test.html",
            price_info=price_info,
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
    
    def test_advertisement_from_dict(self):
        """Test Advertisement from_dict method"""
        ad_dict = {
            'ss_id': '12345678',
            'title': 'Test Apartment',
            'url': 'https://www.ss.lv/test.html',
            'price': 100000.0,
            'currency': 'EUR',
            'location': 'Riga',
            'area': 50.0,
            'rooms': 2
        }
        
        ad = Advertisement.from_dict(ad_dict)
        
        assert ad.ss_id == "12345678"
        assert ad.title == "Test Apartment"
        assert ad.price_info.price == 100000.0
        assert ad.price_info.currency == "EUR"
        assert ad.location == "Riga"
        assert ad.area == 50.0
        assert ad.rooms == 2
