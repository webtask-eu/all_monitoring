#!/usr/bin/env python3
"""
Simple Interactive SS.lv Monitor Bot - Main Entry Point
"""
import asyncio
import logging
import sys
from pathlib import Path

# Add src to path
sys.path.insert(0, str(Path(__file__).parent / "src"))

from ss_monitor.config import config
from ss_monitor.database import DatabaseManager
from ss_monitor.database.user_manager import UserManager
from ss_monitor.bot.interactive_bot import InteractiveSSMonitorBot

# Setup logging
logging.basicConfig(
    level=getattr(logging, config.LOG_LEVEL),
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(config.LOG_FILE),
        logging.StreamHandler()
    ]
)

logger = logging.getLogger(__name__)

def main():
    """Main entry point"""
    try:
        logger.info("Starting Simple Interactive SS.lv Monitor Bot...")
        
        # Initialize database
        logger.info("Initializing database...")
        db_manager = DatabaseManager()
        db_manager._init_database()
        
        # Initialize user manager
        user_manager = UserManager(db_manager)
        
        # Initialize bot
        logger.info("Initializing interactive bot...")
        bot = InteractiveSSMonitorBot(db_manager)
        
        # Start bot
        logger.info("Bot is ready! Send /start to your bot in Telegram")
        logger.info("Press Ctrl+C to stop the bot")
        
        # Run bot
        bot.run()
        
    except KeyboardInterrupt:
        logger.info("Received keyboard interrupt, shutting down...")
    except Exception as e:
        logger.error(f"Error starting bot: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()
