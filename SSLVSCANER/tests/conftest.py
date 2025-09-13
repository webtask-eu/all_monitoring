"""
Pytest configuration and fixtures
"""
import pytest
import tempfile
import os
from unittest.mock import Mock, patch
from src.ss_monitor.database.database_manager import DatabaseManager
from src.ss_monitor.parser.ss_parser import SSParser

@pytest.fixture
def temp_db():
    """Create temporary database for testing"""
    with tempfile.NamedTemporaryFile(suffix='.db', delete=False) as tmp:
        db_path = tmp.name
    
    db_manager = DatabaseManager(db_path)
    yield db_manager
    
    # Cleanup
    os.unlink(db_path)

@pytest.fixture
def mock_parser():
    """Create mock parser for testing"""
    with patch('src.ss_monitor.parser.ss_parser.requests.Session') as mock_session:
        parser = SSParser()
        yield parser

@pytest.fixture
def sample_advertisement_data():
    """Sample advertisement data for testing"""
    return {
        'ss_id': '12345678',
        'title': 'Test Apartment',
        'url': 'https://www.ss.lv/lv/real-estate/flats/riga/test.html',
        'price': 100000.0,
        'currency': 'EUR',
        'location': 'Riga',
        'area': 50.0,
        'rooms': 2,
        'floor': 3,
        'total_floors': 5,
        'property_type': 'apartment'
    }

@pytest.fixture
def sample_html():
    """Sample HTML for testing"""
    return """
    <html>
        <body>
            <table id="page_main">
                <tr id="tr_12345678">
                    <td class="msga2-o">Riga</td>
                    <td class="msga2-o">2</td>
                    <td class="msga2-o">50 m²</td>
                    <td class="msga2-o">3/5</td>
                    <td class="msga2-o">
                        <a class="am" href="/lv/real-estate/flats/riga/test.html">Test Apartment</a>
                    </td>
                    <td class="msga2-o">100,000 €</td>
                </tr>
            </table>
        </body>
    </html>
    """
