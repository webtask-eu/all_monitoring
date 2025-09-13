"""
Database models for SS.lv Monitor
"""
from dataclasses import dataclass
from typing import Optional, List, Dict, Any
from datetime import datetime

@dataclass
class AdvertisementModel:
    """Advertisement database model"""
    id: Optional[int] = None
    ss_id: str = ""
    title: str = ""
    url: str = ""
    price: Optional[float] = None
    currency: Optional[str] = None
    price_per_sqm: Optional[float] = None
    is_monthly: bool = False
    location: Optional[str] = None
    area: Optional[float] = None
    rooms: Optional[int] = None
    floor: Optional[int] = None
    total_floors: Optional[int] = None
    property_type: Optional[str] = None
    image_url: Optional[str] = None
    description: Optional[str] = None
    created_at: Optional[datetime] = None
    updated_at: Optional[datetime] = None
    is_active: bool = True
    
    def to_dict(self) -> Dict[str, Any]:
        """Convert to dictionary"""
        return {
            'id': self.id,
            'ss_id': self.ss_id,
            'title': self.title,
            'url': self.url,
            'price': self.price,
            'currency': self.currency,
            'price_per_sqm': self.price_per_sqm,
            'is_monthly': self.is_monthly,
            'location': self.location,
            'area': self.area,
            'rooms': self.rooms,
            'floor': self.floor,
            'total_floors': self.total_floors,
            'property_type': self.property_type,
            'image_url': self.image_url,
            'description': self.description,
            'created_at': self.created_at.isoformat() if self.created_at else None,
            'updated_at': self.updated_at.isoformat() if self.updated_at else None,
            'is_active': self.is_active
        }
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'AdvertisementModel':
        """Create from dictionary"""
        return cls(
            id=data.get('id'),
            ss_id=data.get('ss_id', ''),
            title=data.get('title', ''),
            url=data.get('url', ''),
            price=data.get('price'),
            currency=data.get('currency'),
            price_per_sqm=data.get('price_per_sqm'),
            is_monthly=data.get('is_monthly', False),
            location=data.get('location'),
            area=data.get('area'),
            rooms=data.get('rooms'),
            floor=data.get('floor'),
            total_floors=data.get('total_floors'),
            property_type=data.get('property_type'),
            image_url=data.get('image_url'),
            description=data.get('description'),
            created_at=datetime.fromisoformat(data['created_at']) if data.get('created_at') else None,
            updated_at=datetime.fromisoformat(data['updated_at']) if data.get('updated_at') else None,
            is_active=data.get('is_active', True)
        )

@dataclass
class PriceHistoryModel:
    """Price history database model"""
    id: Optional[int] = None
    advertisement_id: int = 0
    old_price: Optional[float] = None
    new_price: Optional[float] = None
    currency: Optional[str] = None
    change_type: str = "price_change"  # price_change, new_ad, deleted
    created_at: Optional[datetime] = None
    
    def to_dict(self) -> Dict[str, Any]:
        """Convert to dictionary"""
        return {
            'id': self.id,
            'advertisement_id': self.advertisement_id,
            'old_price': self.old_price,
            'new_price': self.new_price,
            'currency': self.currency,
            'change_type': self.change_type,
            'created_at': self.created_at.isoformat() if self.created_at else None
        }
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'PriceHistoryModel':
        """Create from dictionary"""
        return cls(
            id=data.get('id'),
            advertisement_id=data.get('advertisement_id', 0),
            old_price=data.get('old_price'),
            new_price=data.get('new_price'),
            currency=data.get('currency'),
            change_type=data.get('change_type', 'price_change'),
            created_at=datetime.fromisoformat(data['created_at']) if data.get('created_at') else None
        )

@dataclass
class SubscriptionModel:
    """Subscription database model"""
    id: Optional[int] = None
    user_id: str = ""
    category: str = ""
    city: str = ""
    url: str = ""  # Full URL for the subscription
    frequency: str = "1h"  # Scanning frequency: 1h, 4h, 12h, 1d
    min_price: Optional[float] = None
    max_price: Optional[float] = None
    min_area: Optional[float] = None
    max_area: Optional[float] = None
    min_rooms: Optional[int] = None
    max_rooms: Optional[int] = None
    is_active: bool = True
    created_at: Optional[datetime] = None
    updated_at: Optional[datetime] = None
    
    def to_dict(self) -> Dict[str, Any]:
        """Convert to dictionary"""
        return {
            'id': self.id,
            'user_id': self.user_id,
            'category': self.category,
            'city': self.city,
            'url': self.url,
            'frequency': self.frequency,
            'min_price': self.min_price,
            'max_price': self.max_price,
            'min_area': self.min_area,
            'max_area': self.max_area,
            'min_rooms': self.min_rooms,
            'max_rooms': self.max_rooms,
            'is_active': self.is_active,
            'created_at': self.created_at.isoformat() if self.created_at else None,
            'updated_at': self.updated_at.isoformat() if self.updated_at else None
        }
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'SubscriptionModel':
        """Create from dictionary"""
        return cls(
            id=data.get('id'),
            user_id=data.get('user_id', ''),
            category=data.get('category', ''),
            city=data.get('city', ''),
            url=data.get('url', ''),
            frequency=data.get('frequency', '1h'),
            min_price=data.get('min_price'),
            max_price=data.get('max_price'),
            min_area=data.get('min_area'),
            max_area=data.get('max_area'),
            min_rooms=data.get('min_rooms'),
            max_rooms=data.get('max_rooms'),
            is_active=data.get('is_active', True),
            created_at=datetime.fromisoformat(data['created_at']) if data.get('created_at') else None,
            updated_at=datetime.fromisoformat(data['updated_at']) if data.get('updated_at') else None
        )
