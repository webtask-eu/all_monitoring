"""
Data models for SS.lv parser
"""
from dataclasses import dataclass
from typing import Optional, Dict, Any
from datetime import datetime

@dataclass
class PriceInfo:
    """Price information for an advertisement"""
    price: Optional[float]
    currency: Optional[str]
    price_per_sqm: Optional[float] = None
    is_monthly: bool = False
    
    def __post_init__(self):
        """Validate price info after initialization"""
        if self.price is not None and self.price < 0:
            raise ValueError("Price cannot be negative")
        if self.currency and self.currency not in ['EUR', 'USD', 'LVL']:
            raise ValueError(f"Unsupported currency: {self.currency}")

@dataclass
class Advertisement:
    """Advertisement data model"""
    ss_id: str
    title: str
    url: str
    price_info: PriceInfo
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
    
    def __post_init__(self):
        """Validate advertisement data after initialization"""
        if not self.ss_id:
            raise ValueError("SS ID is required")
        if not self.title:
            raise ValueError("Title is required")
        if not self.url:
            raise ValueError("URL is required")
        if self.area is not None and self.area <= 0:
            raise ValueError("Area must be positive")
        if self.rooms is not None and self.rooms <= 0:
            raise ValueError("Rooms must be positive")
    
    def to_dict(self) -> Dict[str, Any]:
        """Convert to dictionary"""
        return {
            'ss_id': self.ss_id,
            'title': self.title,
            'url': self.url,
            'price': self.price_info.price,
            'currency': self.price_info.currency,
            'price_per_sqm': self.price_info.price_per_sqm,
            'is_monthly': self.price_info.is_monthly,
            'location': self.location,
            'area': self.area,
            'rooms': self.rooms,
            'floor': self.floor,
            'total_floors': self.total_floors,
            'property_type': self.property_type,
            'image_url': self.image_url,
            'description': self.description,
            'created_at': self.created_at.isoformat() if self.created_at else None,
            'updated_at': self.updated_at.isoformat() if self.updated_at else None
        }
    
    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> 'Advertisement':
        """Create from dictionary"""
        price_info = PriceInfo(
            price=data.get('price'),
            currency=data.get('currency'),
            price_per_sqm=data.get('price_per_sqm'),
            is_monthly=data.get('is_monthly', False)
        )
        
        return cls(
            ss_id=data['ss_id'],
            title=data['title'],
            url=data['url'],
            price_info=price_info,
            location=data.get('location'),
            area=data.get('area'),
            rooms=data.get('rooms'),
            floor=data.get('floor'),
            total_floors=data.get('total_floors'),
            property_type=data.get('property_type'),
            image_url=data.get('image_url'),
            description=data.get('description'),
            created_at=datetime.fromisoformat(data['created_at']) if data.get('created_at') else None,
            updated_at=datetime.fromisoformat(data['updated_at']) if data.get('updated_at') else None
        )
