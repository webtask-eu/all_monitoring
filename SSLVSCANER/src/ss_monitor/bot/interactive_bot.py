"""
Interactive Telegram Bot for SS.lv Monitor with full UI
"""
import asyncio
import logging
from typing import Dict, List, Optional
from datetime import datetime, timedelta
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup, ReplyKeyboardMarkup, KeyboardButton
from telegram.ext import Application, CommandHandler, CallbackQueryHandler, MessageHandler, filters, ContextTypes

from ..config import config
from ..database import DatabaseManager
from ..database.user_manager import UserManager
from ..parser import SSParser
from ..notifications import NotificationSystem

logger = logging.getLogger(__name__)

class InteractiveSSMonitorBot:
    """Interactive SS.lv Monitor Telegram Bot with full UI"""
    
    def __init__(self, db_manager: DatabaseManager = None):
        self.db_manager = db_manager or DatabaseManager()
        self.user_manager = UserManager(self.db_manager)
        self.parser = SSParser()
        self.notification_system = NotificationSystem(self.db_manager, self)
        self.application = None
        self.user_sessions = {}  # Хранение состояний пользователей
        self._setup_application()
    
    def _setup_application(self):
        """Setup the Telegram application"""
        try:
            self.application = Application.builder().token(config.TELEGRAM_BOT_TOKEN).build()
            self._setup_handlers()
            logger.info("Interactive Telegram application setup completed")
        except Exception as e:
            logger.error(f"Error setting up Telegram application: {e}")
            raise
    
    def _setup_handlers(self):
        """Setup command and message handlers"""
        # Command handlers
        self.application.add_handler(CommandHandler("start", self._start_command))
        self.application.add_handler(CommandHandler("help", self._help_command))
        self.application.add_handler(CommandHandler("status", self._status_command))
        
        # Callback query handlers
        self.application.add_handler(CallbackQueryHandler(self._add_section_callback, pattern="^add_section$"))
        self.application.add_handler(CallbackQueryHandler(self._frequency_callback, pattern="^frequency$"))
        self.application.add_handler(CallbackQueryHandler(self._status_callback, pattern="^status$"))
        self.application.add_handler(CallbackQueryHandler(self._manage_sections_callback, pattern="^manage_sections$"))
        self.application.add_handler(CallbackQueryHandler(self._delete_section_callback, pattern="^delete_section_"))
        
        # Message handlers
        self.application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, self._handle_text_message))
        
        logger.info("Added interactive handlers")
    
    def _get_main_keyboard(self) -> ReplyKeyboardMarkup:
        """Get main menu keyboard"""
        keyboard = [
            ["➕ Добавить раздел", "⏰ Периодичность"],
            ["📊 Статус", "⚙️ Управление разделами"],
            ["🔧 Отладка"]
        ]
        return ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    def _get_add_section_keyboard(self) -> ReplyKeyboardMarkup:
        """Get keyboard for add section mode"""
        keyboard = [
            ["✅ Готово"]
        ]
        return ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    def _get_frequency_keyboard(self) -> ReplyKeyboardMarkup:
        """Get frequency selection keyboard"""
        keyboard = [
            ["1 час", "4 часа"],
            ["12 часов", "1 день"],
            ["◀️ Назад в меню"]
        ]
        return ReplyKeyboardMarkup(keyboard, resize_keyboard=True, one_time_keyboard=False)
    
    async def _start_command(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        """Start command handler"""
        try:
            user_id = str(update.effective_user.id)
            username = update.effective_user.username or "Пользователь"
            
            # Инициализируем сессию пользователя
            self.user_sessions[user_id] = {
                'state': 'main_menu',
                'sections': [],
                'frequency': '1h',
                'last_scan': None
            }
            
            # Get user's existing subscriptions
            subscriptions = self.user_manager.get_user_subscriptions(user_id)
            if subscriptions:
                self.user_sessions[user_id]['sections'] = [sub.url for sub in subscriptions]
                self.user_sessions[user_id]['frequency'] = subscriptions[0].frequency
            
            welcome_text = f"""
🏠 Добро пожаловать в SS.lv Monitor Bot v{config.__version__}!

Привет, {username}! 👋

Я помогу вам отслеживать объявления недвижимости на ss.lv с удобным интерфейсом.

🔧 Возможности:
• Добавление интересующих разделов
• Настройка периодичности сканирования
• Отслеживание новых объявлений
• Управление подписками

Выберите действие в меню ниже:
"""
            
            await update.message.reply_text(
                welcome_text,
                reply_markup=self._get_main_keyboard()
            )
            
        except Exception as e:
            logger.error(f"Error in start command: {e}")
            await update.message.reply_text("❌ Произошла ошибка при запуске бота")
    
    async def _help_command(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        """Help command handler"""
        help_text = f"""
🏠 SS.lv Monitor Bot v{config.__version__} - Справка

📋 Основные функции:
• ➕ Добавить раздел - добавьте ссылки на интересующие разделы
• ⏰ Периодичность - настройте частоту сканирования
• 📊 Статус - посмотрите текущее состояние бота
• ⚙️ Управление разделами - редактируйте ваши подписки

🔧 Как использовать:
1. Нажмите "Добавить раздел"
2. Отправьте ссылку на раздел ss.lv
3. Настройте периодичность сканирования
4. Бот будет автоматически сканировать выбранные разделы

📊 Поддерживаемые разделы:
• Квартиры в Риге: https://www.ss.lv/lv/real-estate/flats/riga/
• Дома в Риге: https://www.ss.lv/lv/real-estate/homes-summer-residences/riga/
• Квартиры в Юрмале: https://www.ss.lv/lv/real-estate/flats/jurmala/
• И любые другие разделы ss.lv

⏰ Периодичность:
• 1 час - частое сканирование
• 4 часа - умеренное сканирование  
• 12 часов - редкое сканирование
• 1 день - ежедневное сканирование

🔧 Автор: {config.__author__}
🌐 Сайт: {config.__website__}
"""
        await update.message.reply_text(help_text)
    
    async def _add_section_callback(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        """Handle add section button"""
        query = update.callback_query
        await query.answer()
        
        user_id = str(query.from_user.id)
        self.user_sessions[user_id]['state'] = 'waiting_for_url'
        
        await query.edit_message_text(
            "➕ Добавление раздела\n\n"
            "Отправьте ссылку на интересующий вас раздел ss.lv\n\n"
            "Примеры ссылок:\n"
            "• https://www.ss.lv/lv/real-estate/flats/riga/\n"
            "• https://www.ss.lv/lv/real-estate/homes-summer-residences/riga/\n"
            "• https://www.ss.lv/lv/real-estate/flats/jurmala/\n\n"
            "Или отправьте несколько ссылок через запятую",
            reply_markup=self._get_main_keyboard()
        )
    
    async def _frequency_callback(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        """Handle frequency button"""
        query = update.callback_query
        await query.answer()
        
        user_id = str(query.from_user.id)
        current_freq = self.user_sessions.get(user_id, {}).get('frequency', '1h')
        
        await query.edit_message_text(
            f"⏰ Настройка периодичности\n\n"
            f"Текущая периодичность: {self._format_frequency(current_freq)}\n\n"
            "Выберите новую периодичность:",
            reply_markup=self._get_frequency_keyboard()
        )
    
    async def _status_callback(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        """Handle status button"""
        query = update.callback_query
        await query.answer()
        
        user_id = str(query.from_user.id)
        subscriptions = self.user_manager.get_user_subscriptions(user_id)
        user_stats = self.user_manager.get_user_stats(user_id)
        
        # Get database stats
        total_ads = self.db_manager.get_total_ads_count()
        
        status_text = f"""
📊 Статус системы

🤖 Бот: Работает ✅
📈 Всего объявлений в базе: {total_ads}
👤 Ваши разделы: {len(subscriptions)}
⏰ Периодичность: {self._format_frequency(user_stats['frequency'])}

📋 Ваши разделы:
{chr(10).join([f"• {sub.url}" for sub in subscriptions]) if subscriptions else "• Нет разделов"}

🔄 Последнее обновление: {user_stats['last_updated'].strftime('%Y-%m-%d %H:%M') if user_stats['last_updated'] else 'Никогда'}

✅ Система готова к работе!
"""
        
        await query.edit_message_text(
            status_text,
            reply_markup=self._get_main_keyboard()
        )
    
    async def _manage_sections_callback(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        """Handle manage sections button"""
        query = update.callback_query
        await query.answer()
        
        user_id = str(query.from_user.id)
        subscriptions = self.user_manager.get_user_subscriptions(user_id)
        
        if not subscriptions:
            await query.edit_message_text(
                "⚙️ Управление разделами\n\n"
                "У вас пока нет добавленных разделов.\n"
                "Используйте кнопку 'Добавить раздел' для добавления ссылок.",
                reply_markup=InlineKeyboardMarkup([[
                    InlineKeyboardButton("◀️ Назад в меню", callback_data="back_to_main")
                ]])
            )
            return
        
        keyboard = []
        for subscription in subscriptions:
            display_name = self._format_url_for_display(subscription.url)
            keyboard.append([InlineKeyboardButton(
                f"🗑️ {display_name}",
                callback_data=f"delete_section_{subscription.id}"
            )])
        
        
        sections_text = "\n".join([f"• {self._format_url_for_display(sub.url)}" for sub in subscriptions])
        
        await query.edit_message_text(
            f"⚙️ Управление разделами\n\n"
            f"Ваши разделы:\n{sections_text}\n\n"
            "Нажмите на раздел для удаления:",
            reply_markup=InlineKeyboardMarkup(keyboard)
        )
    
    
    async def _delete_section_callback(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        """Handle section deletion"""
        query = update.callback_query
        await query.answer()
        
        user_id = str(query.from_user.id)
        subscription_id = int(query.data.split('_')[2])
        
        # Get subscription info before deletion
        subscriptions = self.user_manager.get_user_subscriptions(user_id)
        subscription_to_delete = next((sub for sub in subscriptions if sub.id == subscription_id), None)
        
        if not subscription_to_delete:
            await query.edit_message_text(
                "❌ Раздел не найден или уже удален."
            )
            return
        
        # Delete from database
        success = self.user_manager.delete_user_subscription(user_id, subscription_id)
        
        if success:
            display_name = self._format_url_for_display(subscription_to_delete.url)
            
            # Get updated subscriptions list
            updated_subscriptions = self.user_manager.get_user_subscriptions(user_id)
            
            if not updated_subscriptions:
                # No more subscriptions, show main menu
                await query.edit_message_text(
                    f"✅ Раздел удален: {display_name}\n\n"
                    f"🏠 Главное меню\n\n"
                    "У вас больше нет разделов для мониторинга.\n"
                    "Используйте кнопку '➕ Добавить раздел' для добавления новых разделов.",
                    reply_markup=None
                )
                # Send new message with main keyboard
                await query.message.reply_text(
                    "Выберите действие в меню ниже:",
                    reply_markup=self._get_main_keyboard()
                )
            else:
                # Show updated subscriptions list
                sections_text = "\n".join([f"• {self._format_url_for_display(sub.url)}" for sub in updated_subscriptions])
                
                keyboard = []
                for sub in updated_subscriptions:
                    keyboard.append([InlineKeyboardButton(
                        f"🗑️ {self._format_url_for_display(sub.url)}",
                        callback_data=f"delete_{sub.id}"
                    )])
                
                await query.edit_message_text(
                    f"✅ Раздел удален: {display_name}\n\n"
                    f"⚙️ Управление разделами\n\n"
                    f"Ваши разделы:\n{sections_text}\n\n"
                    "Нажмите на раздел для удаления:",
                    reply_markup=InlineKeyboardMarkup(keyboard)
                )
        else:
            await query.edit_message_text(
                "❌ Ошибка при удалении раздела. Попробуйте еще раз."
            )
    
    
    async def _handle_text_message(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        """Handle text messages"""
        user_id = str(update.effective_user.id)
        text = update.message.text
        
        logger.info(f"Received text message from user {user_id}: {text}")
        
        if user_id not in self.user_sessions:
            self.user_sessions[user_id] = {'state': 'main_menu', 'sections': [], 'frequency': '1h'}
        
        logger.info(f"User {user_id} state: {self.user_sessions[user_id]['state']}")
        
        # Handle keyboard button presses first
        if text == "➕ Добавить раздел":
            await self._add_section_handler(update)
        elif text == "⏰ Периодичность":
            await self._frequency_handler(update)
        elif text == "📊 Статус":
            await self._status_handler(update)
        elif text == "⚙️ Управление разделами":
            await self._manage_sections_handler(update)
        elif text == "🔧 Отладка":
            await self._debug_handler(update)
        elif text == "✅ Готово":
            user_id = str(update.effective_user.id)
            self.user_sessions[user_id]['state'] = 'main_menu'
            
            # Get real data from database
            subscriptions = self.user_manager.get_user_subscriptions(user_id)
            user_stats = self.user_manager.get_user_stats(user_id)
            
            await update.message.reply_text(
                f"✅ Возврат в главное меню\n\n"
                f"🏠 Главное меню\n\n"
                f"📊 Ваши разделы: {len(subscriptions)}\n"
                f"⏰ Периодичность: {self._format_frequency(user_stats['frequency'])}\n\n"
                "Выберите действие:",
                reply_markup=self._get_main_keyboard()
            )
        elif text == "1 час":
            await self._set_frequency_handler(update, "1h")
        elif text == "4 часа":
            await self._set_frequency_handler(update, "4h")
        elif text == "12 часов":
            await self._set_frequency_handler(update, "12h")
        elif text == "1 день":
            await self._set_frequency_handler(update, "1d")
        elif text == "◀️ Назад в меню":
            await self._back_to_main_from_frequency_handler(update)
        elif self.user_sessions[user_id]['state'] == 'waiting_for_url':
            logger.info(f"Processing URLs for user {user_id}, text: {text}")
            await self._process_urls(update, text)
        else:
            logger.info(f"User {user_id} not in waiting_for_url state, current state: {self.user_sessions[user_id]['state']}, text: {text}, showing main menu")
            await update.message.reply_text(
                "Используйте кнопки меню для навигации",
                reply_markup=self._get_main_keyboard()
            )
    
    async def _add_section_handler(self, update: Update):
        """Handle add section button press"""
        user_id = str(update.effective_user.id)
        
        # Initialize user session if not exists
        if user_id not in self.user_sessions:
            self.user_sessions[user_id] = {'state': 'main_menu', 'sections': [], 'frequency': '1h'}
        
        self.user_sessions[user_id]['state'] = 'waiting_for_url'
        logger.info(f"Set user {user_id} state to waiting_for_url")
        
        await update.message.reply_text(
            "➕ Добавление раздела\n\n"
            "Отправьте ссылку на интересующий вас раздел ss.lv\n\n"
            "Примеры ссылок:\n"
            "• https://www.ss.lv/lv/real-estate/flats/riga/\n"
            "• https://www.ss.lv/lv/real-estate/homes-summer-residences/riga/\n"
            "• https://www.ss.lv/lv/real-estate/flats/jurmala/\n\n"
            "Или отправьте несколько ссылок через запятую",
            reply_markup=self._get_add_section_keyboard()
        )
    
    async def _frequency_handler(self, update: Update):
        """Handle frequency button press"""
        user_id = str(update.effective_user.id)
        current_freq = self.user_sessions.get(user_id, {}).get('frequency', '1h')
        
        await update.message.reply_text(
            f"⏰ Настройка периодичности\n\n"
            f"Текущая периодичность: {self._format_frequency(current_freq)}\n\n"
            "Выберите новую периодичность:",
            reply_markup=self._get_frequency_keyboard()
        )
    
    async def _status_handler(self, update: Update):
        """Handle status button press"""
        user_id = str(update.effective_user.id)
        subscriptions = self.user_manager.get_user_subscriptions(user_id)
        user_stats = self.user_manager.get_user_stats(user_id)
        
        # Get database stats
        total_ads = self.db_manager.get_total_ads_count()
        
        status_text = f"""
📊 Статус системы

🤖 Бот: Работает ✅
📈 Всего объявлений в базе: {total_ads}
👤 Ваши разделы: {len(subscriptions)}
⏰ Периодичность: {self._format_frequency(user_stats['frequency'])}

📋 Ваши разделы:
{chr(10).join([f"• {sub.url}" for sub in subscriptions]) if subscriptions else "• Нет разделов"}

🔄 Последнее обновление: {user_stats['last_updated'].strftime('%Y-%m-%d %H:%M') if user_stats['last_updated'] else 'Никогда'}

✅ Система готова к работе!
"""
        
        await update.message.reply_text(
            status_text,
            reply_markup=self._get_main_keyboard()
        )
    
    async def _manage_sections_handler(self, update: Update):
        """Handle manage sections button press"""
        user_id = str(update.effective_user.id)
        subscriptions = self.user_manager.get_user_subscriptions(user_id)
        
        if not subscriptions:
            await update.message.reply_text(
                "⚙️ Управление разделами\n\n"
                "У вас пока нет добавленных разделов.\n"
                "Используйте кнопку 'Добавить раздел' для добавления ссылок.",
                reply_markup=self._get_main_keyboard()
            )
            return
        
        # Create keyboard with subscription buttons
        keyboard = []
        for sub in subscriptions:
            display_name = self._format_url_for_display(sub.url)
            keyboard.append([InlineKeyboardButton(
                f"🗑️ {display_name}",
                callback_data=f"delete_section_{sub.id}"
            )])
        
        sections_text = "\n".join([f"• {self._format_url_for_display(sub.url)}" for sub in subscriptions])
        
        await update.message.reply_text(
            f"⚙️ Управление разделами\n\n"
            f"Ваши разделы:\n{sections_text}\n\n"
            "Нажмите на раздел для удаления:",
            reply_markup=InlineKeyboardMarkup(keyboard)
        )
    
    
    async def _set_frequency_handler(self, update: Update, frequency: str):
        """Handle frequency selection button press"""
        user_id = str(update.effective_user.id)
        
        # Update frequency in database
        success = self.user_manager.update_user_frequency(user_id, frequency)
        
        if success:
            # Update session
            self.user_sessions[user_id]['frequency'] = frequency
            
            await update.message.reply_text(
                f"✅ Периодичность обновлена!\n\n"
                f"Новая периодичность: {self._format_frequency(frequency)}\n\n"
                "Бот будет сканировать ваши разделы с выбранной частотой.",
                reply_markup=self._get_main_keyboard()
            )
        else:
            await update.message.reply_text(
                "❌ Ошибка при обновлении периодичности.\n"
                "Попробуйте еще раз.",
                reply_markup=self._get_frequency_keyboard()
            )
    
    async def _back_to_main_from_frequency_handler(self, update: Update):
        """Handle back to main menu from frequency selection"""
        user_id = str(update.effective_user.id)
        
        # Get real data from database
        subscriptions = self.user_manager.get_user_subscriptions(user_id)
        user_stats = self.user_manager.get_user_stats(user_id)
        
        await update.message.reply_text(
            f"🏠 Главное меню\n\n"
            f"📊 Ваши разделы: {len(subscriptions)}\n"
            f"⏰ Периодичность: {self._format_frequency(user_stats['frequency'])}\n\n"
            "Выберите действие:",
            reply_markup=self._get_main_keyboard()
        )
    
    async def _debug_handler(self, update: Update):
        """Handle debug button press"""
        user_id = str(update.effective_user.id)
        subscriptions = self.user_manager.get_user_subscriptions(user_id)
        
        if not subscriptions:
            await update.message.reply_text(
                "🔧 Отладка\n\n"
                "У вас нет добавленных разделов для тестирования.\n"
                "Добавьте разделы через кнопку 'Добавить раздел'.",
                reply_markup=self._get_main_keyboard()
            )
            return
        
        await update.message.reply_text(
            "🔧 Отладка\n\n"
            "Сканирую ваши разделы...\n"
            "Это может занять несколько секунд.",
            reply_markup=self._get_main_keyboard()
        )
        
        # Test each subscription
        debug_results = []
        for sub in subscriptions:
            try:
                ads = await self._test_parse_section(sub.url)
                debug_results.append({
                    'url': sub.url,
                    'display_name': self._format_url_for_display(sub.url),
                    'ads': ads[:2]  # Last 2 ads
                })
            except Exception as e:
                debug_results.append({
                    'url': sub.url,
                    'display_name': self._format_url_for_display(sub.url),
                    'error': str(e)
                })
        
        # Format results
        result_text = "🔧 Результаты отладки (объявления о продаже)\n\n"
        
        for result in debug_results:
            result_text += f"📋 {result['display_name']}\n"
            if 'error' in result:
                result_text += f"❌ Ошибка: {result['error']}\n\n"
            elif result['ads']:
                result_text += f"✅ Найдено объявлений о продаже: {len(result['ads'])}\n"
                for i, ad in enumerate(result['ads'], 1):
                    result_text += f"{i}. {ad.get('title', 'Без названия')}\n"
                    if ad.get('price'):
                        result_text += f"   💰 {ad['price']}\n"
                    if ad.get('location'):
                        result_text += f"   📍 {ad['location']}\n"
                    if ad.get('link'):
                        result_text += f"   🔗 {ad['link']}\n"
                result_text += "\n"
            else:
                result_text += "⚠️ Объявления о продаже не найдены\n"
                result_text += "Проверьте правильность ссылки\n\n"
        
        await update.message.reply_text(
            result_text,
            reply_markup=self._get_main_keyboard()
        )
    
    async def _test_parse_section(self, url: str) -> list:
        """Test parse a section and return ads"""
        import requests
        from bs4 import BeautifulSoup
        
        try:
            headers = {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            }
            
            # Always try with /all/sell/ first for better results
            if not url.endswith('/all/sell/'):
                target_url = url.rstrip('/') + '/all/sell/'
                logger.info(f"Trying URL with /all/sell/: {target_url}")
            else:
                target_url = url
            
            response = requests.get(target_url, headers=headers, timeout=10)
            response.raise_for_status()
            
            soup = BeautifulSoup(response.content, 'html.parser')
            ads = self._extract_ads_from_soup(soup)
            
            # If no ads found with /all/sell/, try original URL as fallback
            if not ads and target_url != url:
                logger.info(f"No ads found in {target_url}, trying original URL: {url}")
                try:
                    response = requests.get(url, headers=headers, timeout=10)
                    response.raise_for_status()
                    soup = BeautifulSoup(response.content, 'html.parser')
                    ads = self._extract_ads_from_soup(soup)
                except Exception as e:
                    logger.error(f"Error parsing original URL {url}: {e}")
            
            return ads
            
        except Exception as e:
            logger.error(f"Error parsing {url}: {e}")
            return []
    
    def _extract_ads_from_soup(self, soup) -> list:
        """Extract ads from BeautifulSoup object"""
        ads = []
        
        # Find ad containers - look for table rows with specific structure
        ad_containers = soup.find_all('tr', {'id': lambda x: x and x.startswith('tr_')})
        
        for container in ad_containers[:2]:  # Get first 2 ads
            ad = {}
            
            # Extract title and link
            title_elem = container.find('a', {'id': lambda x: x and x.startswith('dm_')})
            if title_elem:
                ad['title'] = title_elem.get_text(strip=True)
                ad['link'] = 'https://www.ss.lv' + title_elem.get('href', '')
            
            # Extract price - look in the right column
            price_cell = container.find('td', {'class': 'msga2-o', 'id': lambda x: x and 'price' in x})
            if price_cell:
                ad['price'] = price_cell.get_text(strip=True)
            
            # Extract location - look for location cell
            location_cell = container.find('td', {'class': 'msga2-o', 'id': lambda x: x and 'pageid' in x})
            if location_cell:
                ad['location'] = location_cell.get_text(strip=True)
            
            # If we found title and link, it's a valid ad
            if ad.get('title') and ad.get('link'):
                ads.append(ad)
        
        return ads
    
    async def _process_urls(self, update: Update, text: str):
        """Process single URL from user input"""
        user_id = str(update.effective_user.id)
        
        logger.info(f"Processing URL for user {user_id}: {text}")
        
        # Validate single URL
        if not self._is_valid_ss_url(text):
            await update.message.reply_text(
                "❌ Некорректная ссылка на ss.lv\n\n"
                "Примеры правильных ссылок:\n" +
                "• https://www.ss.lv/lv/real-estate/flats/riga/\n" +
                "• https://www.ss.lv/lv/real-estate/homes-summer-residences/riga/\n" +
                "• https://www.ss.lv/lv/real-estate/flats/jurmala/\n\n" +
                "Попробуйте еще раз или нажмите '✅ Готово'.",
                reply_markup=self._get_add_section_keyboard()
            )
            return
        
        # Check if URL already exists
        existing_subscriptions = self.user_manager.get_user_subscriptions(user_id)
        if any(sub.url == text for sub in existing_subscriptions):
            await update.message.reply_text(
                "⚠️ Этот раздел уже добавлен!\n\n"
                "Попробуйте добавить другой раздел или нажмите '✅ Готово'.",
                reply_markup=self._get_add_section_keyboard()
            )
            return
        
        # Test parse the section to get examples
        await update.message.reply_text(
            "🔍 Проверяю раздел и получаю примеры объявлений о продаже...\n"
            "Это может занять несколько секунд.",
            reply_markup=self._get_add_section_keyboard()
        )
        
        try:
            ads = await self._test_parse_section(text)
            
            # Save the subscription
            success = self.user_manager.create_user_subscription(
                user_id=user_id,
                url=text,
                frequency="1h"
            )
            
            if not success:
                raise Exception("Не удалось сохранить раздел в базу данных")
            
            # Format success message
            display_name = self._format_url_for_display(text)
            success_text = f"✅ Раздел добавлен: {display_name} (только продажа)\n\n"
            
            if ads:
                success_text += "📋 Примеры объявлений:\n"
                for i, ad in enumerate(ads[:2], 1):
                    success_text += f"{i}. {ad.get('title', 'Без названия')}\n"
                    if ad.get('price'):
                        success_text += f"   💰 {ad['price']}\n"
                    if ad.get('location'):
                        success_text += f"   📍 {ad['location']}\n"
                    if ad.get('link'):
                        success_text += f"   🔗 {ad['link']}\n"
                success_text += "\n"
            else:
                success_text += "⚠️ Объявления о продаже не найдены\n"
                success_text += "Возможные причины:\n"
                success_text += "• Раздел пуст или временно недоступен\n"
                success_text += "• Нет объявлений о продаже (только аренда)\n"
                success_text += "• Неправильная ссылка на раздел\n"
                success_text += "• Проблемы с парсингом сайта\n\n"
            
            # Get updated count
            updated_subscriptions = self.user_manager.get_user_subscriptions(user_id)
            success_text += f"📊 Всего разделов: {len(updated_subscriptions)}\n\n"
            success_text += "💡 Можете добавить еще разделы - просто отправьте ссылку!\n"
            success_text += "Или нажмите '✅ Готово' для возврата в меню."
            
            await update.message.reply_text(
                success_text,
                reply_markup=self._get_add_section_keyboard()
            )
            
        except Exception as e:
            logger.error(f"Error processing URL {text}: {e}")
            await update.message.reply_text(
                f"❌ Ошибка при обработке раздела:\n{str(e)}\n\n"
                "Попробуйте еще раз или нажмите '✅ Готово'.",
                reply_markup=self._get_add_section_keyboard()
            )
    
    def _is_valid_ss_url(self, url: str) -> bool:
        """Check if URL is valid ss.lv URL"""
        return url.startswith('https://www.ss.lv/') and 'real-estate' in url
    
    def _format_url_for_display(self, url: str) -> str:
        """Format URL for display by parsing the page title"""
        try:
            # Try to get the display name from the page
            display_name = self._get_page_display_name(url)
            if display_name:
                return display_name
        except Exception as e:
            logger.warning(f"Could not parse display name for {url}: {e}")
        
        # Fallback to URL-based parsing
        if 'flats' in url:
            return "Квартиры"
        elif 'homes-summer-residences' in url:
            return "Дома"
        else:
            return url.replace('https://www.ss.lv/', 'ss.lv/')
    
    def _get_page_display_name(self, url: str) -> str:
        """Get display name by parsing the page title"""
        import requests
        from bs4 import BeautifulSoup
        
        try:
            headers = {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            }
            
            # Add /all/sell/ to URL for better results
            if not url.endswith('/all/sell/'):
                target_url = url.rstrip('/') + '/all/sell/'
            else:
                target_url = url
            
            response = requests.get(target_url, headers=headers, timeout=10)
            response.raise_for_status()
            
            soup = BeautifulSoup(response.content, 'html.parser')
            
            # Get title from page
            title_element = soup.find('title')
            if title_element:
                title = title_element.get_text(strip=True)
                # Extract meaningful part from title (usually after " - ")
                if ' - ' in title:
                    parts = title.split(' - ')
                    if len(parts) >= 2:
                        return parts[1].strip()
                # If no " - " found, return the whole title
                return title
            
            return None
            
        except Exception as e:
            logger.error(f"Error parsing display name from {url}: {e}")
            return None
    
    def _format_frequency(self, freq: str) -> str:
        """Format frequency for display"""
        freq_map = {
            '1h': '1 час',
            '4h': '4 часа', 
            '12h': '12 часов',
            '1d': '1 день'
        }
        return freq_map.get(freq, '1 час')
    
    async def _status_command(self, update: Update, context: ContextTypes.DEFAULT_TYPE):
        """Status command handler"""
        try:
            user_id = str(update.effective_user.id)
            subscriptions = self.user_manager.get_user_subscriptions(user_id)
            user_stats = self.user_manager.get_user_stats(user_id)
            
            # Get database stats
            total_ads = self.db_manager.get_total_ads_count()
            
            status_text = f"""
📊 Статус системы

🤖 Бот: Работает ✅
📈 Всего объявлений в базе: {total_ads}
👤 Ваши разделы: {len(subscriptions)}
⏰ Периодичность: {self._format_frequency(user_stats['frequency'])}

📋 Ваши разделы:
{chr(10).join([f"• {sub.url}" for sub in subscriptions]) if subscriptions else "• Нет разделов"}

🔄 Последнее обновление: {user_stats['last_updated'].strftime('%Y-%m-%d %H:%M') if user_stats['last_updated'] else 'Никогда'}

✅ Система готова к работе!
"""
            
            await update.message.reply_text(
                status_text,
                reply_markup=self._get_main_keyboard()
            )
            
        except Exception as e:
            logger.error(f"Error in status command: {e}")
            await update.message.reply_text("❌ Ошибка получения статуса")
    
    async def send_message(self, chat_id: str, message: str):
        """Send message to user"""
        try:
            await self.application.bot.send_message(chat_id=chat_id, text=message)
        except Exception as e:
            logger.error(f"Error sending message to {chat_id}: {e}")
    
    def run(self):
        """Run the bot"""
        try:
            logger.info("Starting Interactive SS.lv Monitor Bot...")
            
            # Start bot
            logger.info("Bot is ready! Send /start to your bot in Telegram")
            logger.info("Press Ctrl+C to stop the bot")
            
            # Run the bot
            self.application.run_polling(drop_pending_updates=True)
            
        except KeyboardInterrupt:
            logger.info("Received keyboard interrupt, shutting down...")
        except Exception as e:
            logger.error(f"Error starting bot: {e}")
            raise
