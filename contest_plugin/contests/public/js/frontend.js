jQuery(document).ready(function($) {
    // Функция для обновления данных конкурса
    function updateContestData() {
        if (!ftTraderFrontend.isUserLoggedIn) {
            $.ajax({
                url: ftTraderFrontend.ajaxurl,
                type: 'POST',
                data: {
                    action: 'update_contests_data',
                    nonce: ftTraderFrontend.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Обновляем данные на странице
                        updateContestDisplay(response.data);
                    }
                },
                error: function() {
                    console.error('Ошибка при обновлении данных конкурса');
                }
            });
        }
    }

    // Функция для обновления отображения данных
    function updateContestDisplay(data) {
        // Обновляем таблицу участников
        if (data.participants) {
            updateParticipantsTable(data.participants);
        }
        
        // Обновляем статусы
        if (data.status) {
            updateContestStatus(data.status);
        }

        // Обновляем статистику конкурса
        if (data.statistics) {
            updateContestStatistics(data.statistics);
        }
    }

    // Функция обновления таблицы участников
    function updateParticipantsTable(participants) {
        const $table = $('.contest-participants-table');
        if (!$table.length) return;

        // Обновляем данные в таблице
        participants.forEach(function(participant) {
            const $row = $table.find(`tr[data-account-id="${participant.id}"]`);
            if ($row.length) {
                $row.find('.equity').text(participant.equity);
                $row.find('.status').text(participant.status);
                // Обновляем другие поля по необходимости
            }
        });
    }

    // Функция обновления статуса конкурса
    function updateContestStatus(status) {
        const $statusElement = $('.contest-status');
        if ($statusElement.length) {
            $statusElement.text(status);
        }
    }

    // Анимация значений статистики при загрузке страницы
    function initializeStatistics() {
        // Если блок статистики не найден, прекращаем выполнение
        if (!$('#contest-stats').length) return;
        
        // Анимируем прогресс-бары
        setTimeout(function() {
            $('.stat-progress-fill').each(function() {
                const $this = $(this);
                const width = $this.css('width');
                $this.css('width', '0').animate({width: width}, 1000);
            });
            
            $('.stat-ratio-profit, .stat-ratio-loss').each(function() {
                const $this = $(this);
                const width = $this.css('width');
                $this.css('width', '0').animate({width: width}, 1000);
            });
            
            // Анимируем счетчики
            animateCounters();
        }, 300);
    }
    
    // Анимация счетчиков
    function animateCounters() {
        $('.contest-stat-value').each(function() {
            const $this = $(this);
            const value = $this.data('value');
            
            // Если значение не числовое, не анимируем
            if (typeof value !== 'number' || isNaN(value)) return;
            
            // Сохраняем текущее форматирование ($ и %)
            const text = $this.text();
            const prefix = text.match(/^[^\d-.]*/)[0];
            const suffix = text.match(/[^\d-.]*$/)[0];
            
            // Создаем счетчик
            $({ Counter: 0 }).animate({
                Counter: value
            }, {
                duration: 1500,
                easing: 'swing',
                step: function() {
                    // Форматируем с двумя знаками после запятой, если это не целое число
                    const val = this.Counter;
                    const formatted = Number.isInteger(val) ? 
                        Math.floor(val) : 
                        val.toFixed(2);
                    $this.text(prefix + formatted + suffix);
                },
                complete: function() {
                    // Убеждаемся, что в конце установлено точное значение
                    const finalValue = Number.isInteger(value) ? 
                        value : 
                        value.toFixed(2);
                    $this.text(prefix + finalValue + suffix);
                }
            });
        });
    }
    
    // Функция обновления статистики конкурса
    function updateContestStatistics(stats) {
        // Если блок статистики не найден, прекращаем выполнение
        if (!$('#contest-stats').length) return;
        
        // Обновляем штамп времени
        if (stats.timestamp) {
            $('#stats-timestamp').html('Обновлено: ' + stats.timestamp);
        }
        
        // Обновляем каждую карточку статистики с анимацией
        if (stats.traders) {
            updateStatCard('traders', stats.traders);
        }
        
        if (stats.pnl) {
            updateStatCard('pnl', stats.pnl);
        }
        
        if (stats.trades) {
            updateStatCard('trades', stats.trades);
        }
        
        if (stats.efficiency) {
            updateStatCard('efficiency', stats.efficiency);
        }
    }
    
    // Функция обновления одной карточки статистики
    function updateStatCard(cardId, data) {
        const $card = $('#stat-card-' + cardId);
        if (!$card.length) return;
        
        // Добавляем класс обновления для анимации
        $card.addClass('stat-updating');
        
        // Через время удаляем класс анимации
        setTimeout(function() {
            $card.removeClass('stat-updating');
        }, 1500);
        
        // Обновляем основное значение с анимацией счетчика
        const $valueElement = $card.find('.contest-stat-value');
        if ($valueElement.length && 'value' in data) {
            const currentValue = parseFloat($valueElement.data('value')) || 0;
            const newValue = parseFloat(data.value) || 0;
            
            // Если значение изменилось, анимируем
            if (currentValue !== newValue) {
                // Определяем классы на основе нового значения
                if (data.is_positive !== undefined) {
                    $valueElement.removeClass('positive negative');
                    if (data.is_positive) {
                        $valueElement.addClass('positive');
                    } else {
                        $valueElement.addClass('negative');
                    }
                }
                
                // Сохраняем текущее форматирование
                const text = $valueElement.text();
                const prefix = text.match(/^[^\d-.]*/)[0];
                const suffix = text.match(/[^\d-.]*$/)[0];
                
                // Устанавливаем новое значение в атрибут data-value
                $valueElement.data('value', newValue);
                
                // Анимируем счетчик
                $({ Counter: currentValue }).animate({
                    Counter: newValue
                }, {
                    duration: 1000,
                    easing: 'swing',
                    step: function() {
                        const val = this.Counter;
                        const formatted = Number.isInteger(val) ? 
                            Math.floor(val) : 
                            val.toFixed(2);
                        $valueElement.text(prefix + formatted + suffix);
                    },
                    complete: function() {
                        const finalValue = Number.isInteger(newValue) ? 
                            newValue : 
                            newValue.toFixed(2);
                        $valueElement.text(prefix + finalValue + suffix);
                    }
                });
            }
        }
        
        // Обновляем дополнительные данные карточки
        if ('details' in data) {
            $.each(data.details, function(key, value) {
                const $detailElement = $card.find('.' + key);
                if ($detailElement.length) {
                    $detailElement.html(value);
                }
            });
        }
        
        // Обновляем прогресс-бары
        if ('progress' in data) {
            const $progressFill = $card.find('.stat-progress-fill');
            if ($progressFill.length) {
                $progressFill.animate({
                    width: data.progress + '%'
                }, 800);
                
                // Обновляем класс цвета, если указан
                if (data.progress_class) {
                    $progressFill.removeClass('profit loss').addClass(data.progress_class);
                }
            }
        }
        
        // Обновляем соотношения
        if (cardId === 'traders' && 'profit_ratio' in data && 'loss_ratio' in data) {
            const $profitRatio = $card.find('.stat-ratio-profit');
            const $lossRatio = $card.find('.stat-ratio-loss');
            
            if ($profitRatio.length && $lossRatio.length) {
                $profitRatio.animate({width: data.profit_ratio + '%'}, 800);
                $lossRatio.animate({width: data.loss_ratio + '%'}, 800);
            }
        }
    }

    // Инициализация тултипов для трейдеров
    function initializeTraderTooltips() {
        // Получаем все элементы с процентом прибыли
        const profitElements = document.querySelectorAll('.leader-profit[data-tooltip-content]');
        
        // Прерываем выполнение, если таких элементов нет
        if (!profitElements.length) return;
        
        // Создаем элемент тултипа, если еще нет
        let tooltip = document.getElementById('trader-tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'trader-tooltip';
            tooltip.className = 'trader-tooltip';
            document.body.appendChild(tooltip);
        }
        
        // Добавляем обработчики событий для каждого элемента с процентом
        profitElements.forEach(element => {
            element.addEventListener('mouseenter', function(e) {
                // Получаем содержимое тултипа
                const content = this.getAttribute('data-tooltip-content');
                tooltip.innerHTML = content;
                tooltip.style.display = 'block';
                
                // Получаем позицию и размеры элемента
                const rect = this.getBoundingClientRect();
                const tooltipWidth = tooltip.offsetWidth;
                
                // Рассчитываем позицию так, чтобы тултип был по центру
                let leftPos = rect.left + (rect.width / 2) - (tooltipWidth / 2) + window.scrollX;
                
                // Проверяем, не выходит ли тултип за границы экрана
                if (leftPos < 10) leftPos = 10;
                if (leftPos + tooltipWidth > window.innerWidth - 10) {
                    leftPos = window.innerWidth - tooltipWidth - 10;
                }
                
                // Позиционируем тултип
                tooltip.style.left = leftPos + 'px';
                tooltip.style.top = (rect.bottom + window.scrollY + 15) + 'px';
            });
            
            element.addEventListener('mouseleave', function() {
                tooltip.style.display = 'none';
            });
        });
    }

    // Запускаем обновление данных
    if (!ftTraderFrontend.isUserLoggedIn) {
        // Обновляем сразу при загрузке
        updateContestData();
        
        // Устанавливаем интервал обновления
        setInterval(updateContestData, ftTraderFrontend.refreshInterval);
    }
    
    // Инициализируем анимацию статистики при загрузке страницы
    initializeStatistics();
    
    // Инициализируем тултипы для трейдеров
    initializeTraderTooltips();
}); 