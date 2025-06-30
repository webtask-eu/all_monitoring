(function ($) {
    'use strict';

    // V2024.05.07 - Добавляем обработчик ошибок для Chart.js
    window.addEventListener('error', function(event) {
        if (event.error && event.error.message && event.error.message.includes('getComputedStyle')) {
            event.preventDefault(); // Предотвращаем всплытие ошибки
            console.warn('Перехвачена ошибка графика: элемент не найден');
            
            // Попытка очистки старых экземпляров графиков
            if (window.Chart && window.Chart.instances) {
                Object.keys(window.Chart.instances).forEach(function(key) {
                    try {
                        window.Chart.instances[key].destroy();
                    } catch(e) {
                        // Игнорируем ошибки при уничтожении
                    }
                });
            }
        }
    }, true);

    // Объект для работы с графиком
    var AccountChart = {
        chart: null,
        accountId: 0,
        currentPeriod: 'month', // Изменено с 'week' на 'month'
        initialized: false, // Добавляем флаг инициализации
        debug: false, // Отключаем отладку

        log: function (message, data) {
            if (this.debug) {
                console.log('AccountChart: ' + message, data || '');
            }
        },

        // Обновляем функцию форматирования даты
        // Обновляем функцию форматирования даты для последовательного отображения меток
        formatDate: function (dateStr, period) {
            // Парсим строку даты
            var date;

            // Определение формата метки в зависимости от строки
            if (dateStr.includes('-')) {
                // Для формата YYYY-MM-DD или YYYY-MM
                date = new Date(dateStr);
            } else if (dateStr.includes('W')) {
                // Для формата YYYY-W (год и номер недели)
                var parts = dateStr.split('W');
                var year = parseInt(parts[0]);
                var week = parseInt(parts[1]);
                date = this.getDateOfWeek(year, week);
            } else if (dateStr.includes(':')) {
                // Для формата времени HH:MM
                date = new Date(dateStr);
            } else {
                // Если не удалось распознать формат, возвращаем строку как есть
                return dateStr;
            }

            if (isNaN(date.getTime())) {
                return dateStr; // Возвращаем исходную строку если парсинг не удался
            }

            // Функция для добавления ведущего нуля
            function padZero(num) {
                return (num < 10 ? '0' : '') + num;
            }

            // Разные форматы в зависимости от периода
            if (period === 'month') {
                // Для месяца: DD.MM
                return padZero(date.getDate()) + '.' + padZero(date.getMonth() + 1);
            } else if (period === 'week') {
                // Для недели тоже используем формат DD.MM как для месяца
                return padZero(date.getDate()) + '.' + padZero(date.getMonth() + 1);
            } else if (period === 'day') {
                // Для дня: HH:MM
                return padZero(date.getHours()) + ':' + padZero(date.getMinutes());
            } else if (period === 'year') {
                // Для года: MM.YYYY
                return padZero(date.getMonth() + 1) + '.' + date.getFullYear();
            } else {
                // Для всех остальных периодов используем стандартный формат DD.MM
                return padZero(date.getDate()) + '.' + padZero(date.getMonth() + 1);
            }
        },


        // Вспомогательная функция для определения даты по номеру недели
        getDateOfWeek: function (year, week) {
            var date = new Date(year, 0, 1);
            date.setDate(date.getDate() + (week - 1) * 7);
            return date;
        },

        // Инициализация
        // Изменить на:
        init: function () {
            // Проверяем, был ли уже инициализирован график
            if (this.initialized) {
                this.log('График уже был инициализирован, пропускаем повторную инициализацию');
                return;
            }

            this.log('Инициализация графика');

            // Проверяем наличие графика на странице
            if ($('#accountChart').length === 0) {
                this.log('Элемент #accountChart не найден в DOM', 'error');
                return;
            }

            // Получаем ID счета (проверяем разные источники для админки и фронтенда)
            this.accountId = $('#accountChart').data('account-id') || $('#update_account_data').data('account-id') || 0;

            this.log('ID счета:', this.accountId);

            if (!this.accountId) {
                this.log('ID счета не найден', 'error');
                return;
            }

            // Инициализируем обработчики событий
            this.initEvents();

            // Загружаем данные и рисуем график
            this.loadChartData('month');

            // Отмечаем, что инициализация произошла
            this.initialized = true;
        },

        // Инициализация обработчиков событий

        // Изменим метод initEvents() для обработки изменения размера окна
        initEvents: function () {
            var self = this;
            this.log('Инициализация обработчиков событий');

            // Переключение периода
            $('#chart_period').on('change', function () {
                var period = $(this).val();
                AccountChart.log('Выбран период:', period);
                AccountChart.loadChartData(period);
            });

            // Обработчик изменения размера окна
            $(window).on('resize', function () {
                // V2024.05.07 - Проверяем, не была ли страница перезагружена или изменена
                if (!document.body.contains(document.getElementById('accountChart'))) {
                    // Если элемент был удален из DOM, уничтожаем экземпляр графика
                    if (self.chart) {
                        try {
                            self.chart.destroy();
                            self.chart = null;
                        } catch (e) {
                            // Игнорируем ошибки при уничтожении
                        }
                    }
                    return;
                }
            
                // Улучшенная проверка V2024.05.06 - более надежная проверка существования графика и canvas
                if (!self.chart) {
                    return; // График не существует, выходим
                }

                var accountChartElement = document.getElementById('accountChart');
                
                // Проверяем, что элемент существует, это действительно DOM элемент,
                // и что canvas элемент находится в DOM
                if (accountChartElement && 
                    accountChartElement instanceof Element && 
                    document.body.contains(accountChartElement) &&
                    self.chart.canvas === accountChartElement) {
                    
                    try {
                        // Безопасно обновляем размер графика
                        self.chart.resize();
                        
                        // Сбрасываем стили ширины, если период не "all" или количество точек небольшое
                        if (self.currentPeriod !== 'all' && 
                            self.chart.data && 
                            self.chart.data.labels && 
                            self.chart.data.labels.length <= 50) {
                            $('.chart-container').css('width', '');
                        }
                    } catch (e) {
                        // Логируем ошибку, если что-то пошло не так
                        self.log('Ошибка при изменении размера графика: ' + e.message, 'error');
                    }
                } else {
                    // Логируем предупреждение, если элемент не найден
                    self.log('Canvas-элемент #accountChart не найден в DOM или график не инициализирован, изменение размера пропущено', 'warn');
                }
            });

            // Добавляем обработчик для кнопки переключения отображения отладочной информации
            $('#toggleDebugInfo').on('click', function () {
                $('#rawDataSection').toggleClass('debug-hidden');
            });
            
            // Инициализируем мини-график Equity, если он есть на странице
            this.initEquityCurve();
        },

        // Инициализация мини-графика Equity v1.0.0
        initEquityCurve: function() {
            var equityCurveElement = document.getElementById('equityCurveChart');
            if (!equityCurveElement) {
                this.log('Элемент #equityCurveChart не найден, пропускаем инициализацию мини-графика');
                return;
            }
            
            this.log('Инициализация мини-графика Equity');
            
            // Получаем ID счета
            var accountId = $(equityCurveElement).data('account-id');
            if (!accountId) {
                this.log('ID счета для мини-графика не найден');
                return;
            }
            
            // Загружаем данные для мини-графика
            this.loadEquityCurveData(accountId);
        },
        
        // Загрузка данных для мини-графика Equity v1.0.0
        loadEquityCurveData: function(accountId) {
            var self = this;
            
            // Отображаем индикатор загрузки в SVG
            var svgElement = document.getElementById('equityCurveChart');
            svgElement.innerHTML = '<text x="50%" y="50%" text-anchor="middle" dominant-baseline="middle" class="equity-curve-loading">Загрузка...</text>';
            
            // Получаем данные через тот же AJAX-запрос, что используется для основного графика
            $.ajax({
                url: ftAccountChart.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_account_chart_data',
                    account_id: accountId,
                    period: 'month', // Всегда используем период "месяц" для мини-графика
                    nonce: ftAccountChart.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        self.renderEquityCurveSVG(response.data);
                    } else {
                        svgElement.innerHTML = '<text x="50%" y="50%" text-anchor="middle" dominant-baseline="middle" class="equity-curve-loading">Нет данных</text>';
                    }
                },
                error: function() {
                    svgElement.innerHTML = '<text x="50%" y="50%" text-anchor="middle" dominant-baseline="middle" class="equity-curve-loading">Ошибка загрузки</text>';
                }
            });
        },
        
        // Рендеринг мини-графика Equity в формате SVG v1.0.0
        renderEquityCurveSVG: function(data) {
            this.log('Рендеринг мини-графика Equity в SVG');
            
            // Проверяем наличие данных
            if (!data || !data.equity || !data.equity.length) {
                this.log('Нет данных для отображения в мини-графике Equity');
                return;
            }
            
            // Получаем значения equity
            var equityValues = [];
            for (var i = 0; i < data.equity.length; i++) {
                if (data.equity[i] && data.equity[i].y !== undefined) {
                    equityValues.push(parseFloat(data.equity[i].y));
                }
            }
            
            if (equityValues.length < 2) {
                this.log('Недостаточно точек для построения мини-графика');
                return;
            }
            
            // Находим минимальное и максимальное значения для масштабирования
            var minValue = Math.min.apply(null, equityValues);
            var maxValue = Math.max.apply(null, equityValues);
            
            // Получаем размеры SVG-элемента
            var svgElement = document.getElementById('equityCurveChart');
            var width = svgElement.clientWidth || 200;
            var height = svgElement.clientHeight || 40;
            
            // Отступы
            var padding = 2;
            
            // Очищаем SVG
            svgElement.innerHTML = '';
            
            // Генерируем путь для линии
            var pathData = '';
            var areaPathData = '';
            
            for (var j = 0; j < equityValues.length; j++) {
                // Масштабируем значения по X и Y
                var x = padding + (j / (equityValues.length - 1)) * (width - 2 * padding);
                var y = height - padding - ((equityValues[j] - minValue) / (maxValue - minValue || 1)) * (height - 2 * padding);
                
                if (j === 0) {
                    pathData += 'M ' + x + ' ' + y;
                    areaPathData += 'M ' + x + ' ' + height + ' L ' + x + ' ' + y;
                } else {
                    pathData += ' L ' + x + ' ' + y;
                    areaPathData += ' L ' + x + ' ' + y;
                }
            }
            
            // Добавляем заключительную точку для области заливки
            areaPathData += ' L ' + (width - padding) + ' ' + height + ' Z';
            
            // Создаем область под линией для заливки
            var areaPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            areaPath.setAttribute('d', areaPathData);
            areaPath.setAttribute('class', 'equity-curve-area');
            svgElement.appendChild(areaPath);
            
            // Создаем линию графика
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', pathData);
            svgElement.appendChild(path);
        },

        // Загрузка данных для графика
        loadChartData: function (period) {
            this.log('Загрузка данных графика для периода:', period);
            var self = this;

            // Сохраняем текущий период
            self.currentPeriod = period;

            // Показываем индикатор загрузки
            $('#chart-loading').removeClass('hidden');

            // Если график уже существует, уничтожаем его
            if (self.chart) {
                self.log('Уничтожаем существующий график');
                self.chart.destroy();
                self.chart = null;
            }

            // Проверяем через Chart.js API, есть ли другие графики на этом canvas
            if (window.Chart && window.Chart.instances) {
                Object.keys(window.Chart.instances).forEach(function (key) {
                    if (window.Chart.instances[key].canvas &&
                        window.Chart.instances[key].canvas.id === 'accountChart') {
                        self.log('Найден и уничтожен дополнительный график через Chart.js API');
                        window.Chart.instances[key].destroy();
                    }
                });
            }
            // Сбрасываем ширину контейнера при переключении периода
            // Добавляем эту строку для сброса ширины
            $('.chart-container').css('width', '');

            // Отправляем AJAX-запрос
            $.ajax({
                url: ftAccountChart.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_account_chart_data',
                    account_id: self.accountId,
                    period: period,
                    nonce: ftAccountChart.nonce
                },
                success: function (response) {
                    if (response.success) {
                        self.renderChart(response.data);
                    } else {
                        $('#chart-loading').text(ftAccountChart.i18n.error);
                    }
                },
                error: function () {
                    $('#chart-loading').text(ftAccountChart.i18n.error);
                }
            });
        },

        // Отрисовка графика
        renderChart: function (data) {
            var self = this;

            // Скрываем индикатор загрузки
            $('#chart-loading').addClass('hidden');

            // Проверяем наличие данных
            if (!data || !data.labels || data.labels.length === 0) {
                $('#chart-loading').text(ftAccountChart.i18n.noData).removeClass('hidden');
                return;
            }

            // V2024.05.06 - Проверяем существование canvas-элемента
            var accountChartElement = document.getElementById('accountChart');
            if (!accountChartElement || !(accountChartElement instanceof Element) || !document.body.contains(accountChartElement)) {
                this.log('Canvas-элемент #accountChart не найден или не является DOM-элементом', 'error');
                return;
            }

            // Получаем родительский контейнер для прокрутки
            var $scrollContainer = $('.chart-scroll-container');
            var $chartContainer = $('.chart-container');

            // Сбрасываем предыдущие настройки ширины и классы
            $chartContainer.css('width', '');
            $chartContainer.removeClass('large-dataset very-large-dataset');

            // Получаем максимальную доступную ширину (ширина родительского контейнера)
            var maxAvailableWidth = $scrollContainer.parent().width();

            // Определяем нужную ширину на основе количества точек
            var minWidthPerPoint = 10; // px на каждую точку данных
            var requiredWidth = data.labels.length * minWidthPerPoint;

            // Устанавливаем классы для стилизации в зависимости от размера набора данных
            if (data.labels.length > 100) {
                $chartContainer.addClass('large-dataset');
            }
            if (data.labels.length > 200) {
                $chartContainer.removeClass('large-dataset').addClass('very-large-dataset');
            }

            // Подготавливаем данные для графика
            var validLabels = [];
            var validBalanceData = [];
            var validEquityData = [];

            // Сначала собираем все уникальные метки времени
            var allLabels = data.labels.slice();

            // Для каждой метки ищем соответствующие данные баланса и equity
            for (var i = 0; i < allLabels.length; i++) {
                var label = allLabels[i];

                // Получаем данные баланса и equity для этой метки
                var balancePoint = data.balance[i];
                var equityPoint = data.equity[i];

                // Добавляем метку в валидные метки
                validLabels.push(label);

                // Добавляем данные баланса (null, если нет)
                if (balancePoint && typeof balancePoint.y !== 'undefined') {
                    validBalanceData.push(balancePoint);
                } else {
                    validBalanceData.push(null);
                }

                // Добавляем данные equity (null, если нет)
                if (equityPoint && typeof equityPoint.y !== 'undefined') {
                    validEquityData.push(equityPoint);
                } else {
                    validEquityData.push(null);
                }
            }

            // Получаем контекст canvas
            var ctx = document.getElementById('accountChart').getContext('2d');

            // Создаем наборы данных
            var datasets = [];

            // Баланс - обычная линия
            var balanceValues = [];
            for (var i = 0; i < validBalanceData.length; i++) {
                if (validBalanceData[i] && typeof validBalanceData[i].y !== 'undefined') {
                    balanceValues.push(validBalanceData[i].y);
                } else {
                    balanceValues.push(null); // null будет создавать разрыв в линии
                }
            }

            datasets.push({
                label: 'Баланс',
                data: balanceValues,
                borderColor: '#4285f4', // Синий цвет
                backgroundColor: 'rgba(66, 133, 244, 0.1)',
                borderWidth: 2,
                fill: false,
                tension: 0.1,
                pointRadius: validLabels.length > 50 ? 0 : 2,
                pointHoverRadius: 5,
                spanGaps: true
            });

            // Добавляем данные equity (зеленый цвет)
            var equityValues = [];
            var hasEquityData = false;

            for (var i = 0; i < validEquityData.length; i++) {
                if (validEquityData[i] && typeof validEquityData[i].y !== 'undefined') {
                    equityValues.push(validEquityData[i].y);
                    hasEquityData = true;
                } else {
                    equityValues.push(null); // null будет создавать разрыв в линии
                }
            }

            if (hasEquityData) {
                datasets.push({
                    label: 'Средства',
                    data: equityValues,
                    borderColor: '#34a853', // Зеленый цвет
                    backgroundColor: 'rgba(52, 168, 83, 0.1)',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.1,
                    pointRadius: validLabels.length > 50 ? 0 : 2,
                    pointHoverRadius: 5,
                    // Делаем линию equity пунктирной
                    borderDash: [5, 5],
                    spanGaps: true
                });
            }

            // Настройки для оси X в зависимости от периода
            var xAxisTickSettings = {
                maxRotation: 0,
                minRotation: 0,
                autoSkip: true,
                callback: function (value, index, ticks) {
                    var label = validLabels[index];
                    return self.formatDate(label, self.currentPeriod);
                }
            };

            // Настраиваем отображение меток в зависимости от периода
            if (self.currentPeriod === 'week') {
                // Для недели устанавливаем настройки как для месяца
                xAxisTickSettings.maxTicksLimit = 7; // Показываем меньше меток для недели
                xAxisTickSettings.autoSkipPadding = 40; // Больше отступ между метками
            } else if (self.currentPeriod === 'month') {
                xAxisTickSettings.maxTicksLimit = 10;
                xAxisTickSettings.autoSkipPadding = 30;
            } else if (self.currentPeriod === 'day') {
                xAxisTickSettings.maxTicksLimit = 12;
                xAxisTickSettings.autoSkipPadding = 20;
            } else if (self.currentPeriod === 'all' || validLabels.length > 100) {
                xAxisTickSettings.maxTicksLimit = 15;
                xAxisTickSettings.autoSkipPadding = 50;
            } else {
                xAxisTickSettings.maxTicksLimit = 10;
                xAxisTickSettings.autoSkipPadding = 30;
            }

            // Опции графика
            var options = {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: validLabels.length > 100 ? 0 : 500 // Отключаем анимацию для больших датасетов
                },
                elements: {
                    point: {
                        radius: validLabels.length > 50 ? 0 : 1, // Отключаем точки при большом количестве данных
                        hoverRadius: 5 // Но показываем при наведении
                    },
                    line: {
                        tension: 0.1
                    }
                },
                plugins: {
                    legend: {
                        display: false // Используем свою легенду
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function (context) {
                                var label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('ru-RU', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    },
                    // Отключаем метки данных на самих точках
                    datalabels: {
                        display: false
                    }
                },
                // Добавляем конфигурацию для отступов
                layout: {
                    padding: {
                        left: 10,
                        right: 10,
                        top: 0,
                        bottom: 30 // Увеличиваем отступ снизу для меток оси X
                    }
                },
                scales: {
                    x: {
                        ticks: xAxisTickSettings,
                        grid: {
                            display: true,
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    y: {
                        beginAtZero: false,
                        grace: '5%',
                        ticks: {
                            callback: function (value) {
                                return new Intl.NumberFormat('ru-RU', {
                                    minimumFractionDigits: 0,
                                    maximumFractionDigits: 0
                                }).format(value);
                            }
                        },
                        grid: {
                            display: true,
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    }
                },

                // Настройки взаимодействия
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            };

            // Если существует предыдущий график, уничтожаем его
            // Дополнительная проверка и уничтожение предыдущего графика
            if (self.chart) {
                self.log('Уничтожаем существующий график перед созданием нового');
                self.chart.destroy();
                self.chart = null;
            }

            // Дополнительная проверка через Chart.js API
            if (window.Chart && window.Chart.instances) {
                Object.keys(window.Chart.instances).forEach(function (key) {
                    if (window.Chart.instances[key].canvas &&
                        window.Chart.instances[key].canvas.id === 'accountChart') {
                        self.log('Найден и уничтожен дополнительный график через Chart.js API');
                        window.Chart.instances[key].destroy();
                    }
                });
            }

            // V2024.05.06 - Повторная проверка наличия canvas перед созданием графика
            var chartCanvas = document.getElementById('accountChart');
            if (!chartCanvas || !(chartCanvas instanceof Element) || !document.body.contains(chartCanvas)) {
                self.log('Canvas-элемент #accountChart не найден или не в DOM перед созданием графика', 'error');
                return;
            }

            try {
                // Создаем график
                self.chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: validLabels,
                        datasets: datasets
                    },
                    options: options
                });

                // После создания графика добавляем прокрутку к началу графика
                setTimeout(function () {
                    // Прокручиваем контейнер в начало (самая старая точка)
                    $('.chart-scroll-container').scrollLeft(0);
                }, 100);
            } catch (e) {
                self.log('Ошибка при создании графика: ' + e.message, 'error');
                $('#chart-loading').text('Ошибка при создании графика').removeClass('hidden');
            }

            // Обновляем легенду
            this.updateLegend(data);
            
            // Рассчитываем и отображаем просадку
            this.calculateDrawdown(data);
        },

        // Функция для расчета и отображения просадки на основе данных графика v1.0.1
        calculateDrawdown: function(data) {
            // Проверка наличия контейнера и данных
            if ($('#drawdown-container').length === 0) {
                return;
            }
            
            if (!data || !data.equity || data.equity.length === 0) {
                $('#drawdown-value').html('<span class="error">Нет данных</span>');
                $('#drawdown-percent').html('<span class="error">—</span>');
                return;
            }
            
            // Создаем массив значений equity (без null)
            var equityValues = [];
            for (var i = 0; i < data.equity.length; i++) {
                if (data.equity[i] && typeof data.equity[i].y === 'number') {
                    equityValues.push({
                        value: data.equity[i].y,
                        date: data.labels[i]
                    });
                }
            }
            
            if (equityValues.length === 0) {
                $('#drawdown-value').html('<span class="error">Нет данных equity</span>');
                $('#drawdown-percent').html('<span class="error">—</span>');
                return;
            }
            
            // Находим максимальную просадку
            var maxDrawdown = 0;
            var maxDrawdownPeak = 0;
            var maxDrawdownTrough = 0;
            var peakDate = '';
            var troughDate = '';
            
            for (var i = 0; i < equityValues.length; i++) {
                var peak = equityValues[i].value;
                var peakDt = equityValues[i].date;
                
                // Ищем минимум после этого пика
                var minAfterPeak = peak;
                var minDate = peakDt;
                
                for (var j = i + 1; j < equityValues.length; j++) {
                    if (equityValues[j].value < minAfterPeak) {
                        minAfterPeak = equityValues[j].value;
                        minDate = equityValues[j].date;
                    }
                }
                
                // Рассчитываем просадку (в процентах)
                var drawdown = (peak - minAfterPeak) / peak * 100;
                
                // Если это максимальная просадка, запоминаем ее
                if (drawdown > maxDrawdown) {
                    maxDrawdown = drawdown;
                    maxDrawdownPeak = peak;
                    maxDrawdownTrough = minAfterPeak;
                    peakDate = peakDt;
                    troughDate = minDate;
                }
            }
            
            // Готовим данные для отображения
            var absoluteDrawdown = maxDrawdownPeak - maxDrawdownTrough;
            
            var formattedAbsoluteDrawdown = new Intl.NumberFormat('ru-RU', { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            }).format(absoluteDrawdown);
            
            var formattedPercentDrawdown = new Intl.NumberFormat('ru-RU', { 
                minimumFractionDigits: 1, 
                maximumFractionDigits: 1 
            }).format(maxDrawdown);
            
            // Обновляем текст тултипа
            // Исправляем извлечение валюты - убираем символ минус из названия валюты v1.0.3
            var rawCurrency = $('.financial-value:first').text().replace(/[\d\s,.]+/g, '').trim();
            var currency = rawCurrency.replace(/-/g, '').trim() || 'USD';
            
            var tooltipText = "Максимальная просадка (Max Drawdown) - это максимальное историческое снижение от пика к минимуму.\n\n";
            tooltipText += "Формула расчета:\n";
            tooltipText += "[(Пик - Минимум) / Пик] * 100%\n\n";
            tooltipText += "(" + new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 
            }).format(maxDrawdownPeak) + " " + currency + " - " + 
            new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 
            }).format(maxDrawdownTrough) + " " + currency + ") / " + 
            new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 
            }).format(maxDrawdownPeak) + " " + currency + " * 100% = " + formattedPercentDrawdown + "%\n\n" +
            "Период: с " + peakDate + " по " + troughDate;
            
            // Обновляем элементы на странице
            $('#drawdown-tooltip').attr('data-tooltip', tooltipText);
            
            // Решение проблемы с неправильным позиционированием минуса v1.0.3
            // Правильно форматируем и сами добавляем символ минуса только перед числом
            $('#drawdown-value')
                .html('-' + formattedAbsoluteDrawdown + ' ' + currency)
                .removeClass('custom-drawdown')
                .addClass('profit-negative');
            
            $('#drawdown-percent')
                .html('-' + formattedPercentDrawdown + '%')
                .removeClass('custom-drawdown')
                .addClass('profit-negative');
        },

        // Обновление легенды
        updateLegend: function (data) {
            var legendContainer = $('#chartLegend');
            legendContainer.empty();

            // Добавляем легенду для баланса
            legendContainer.append(
                '<div class="legend-item">' +
                '<span class="legend-color" style="background-color: #4285f4;"></span>' +
                '<span class="legend-label">Баланс:</span>' +
                '<span class="legend-value">' +
                new Intl.NumberFormat('ru-RU', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(data.current_balance) +
                '</span>' +
                '</div>'
            );

            // Добавляем легенду для средств, если они есть
            if (data.current_equity) {
                legendContainer.append(
                    '<div class="legend-item">' +
                    '<span class="legend-color" style="background-color: #34a853;"></span>' +
                    '<span class="legend-label">Средства:</span>' +
                    '<span class="legend-value">' +
                    new Intl.NumberFormat('ru-RU', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }).format(data.current_equity) +
                    '</span>' +
                    '</div>'
                );
            }
            
            // Обновляем лог значений графика v1
            this.updateChartDataLog(data);
        },
        
        // Отображение лога значений графика v1
        updateChartDataLog: function (data) {
            // Проверяем наличие элемента для лога
            var $chartDataLog = $('#chartDataLog');
            if ($chartDataLog.length === 0) {
                return;
            }
            
            // Очищаем предыдущие данные
            $chartDataLog.empty();
            
            // Подготавливаем данные для вывода
            var htmlContent = '<table style="width: 100%; border-collapse: collapse;">';
            htmlContent += '<thead><tr>' + 
                '<th style="border: 1px solid #ddd; padding: 4px; text-align: left; background-color: #f5f5f5;">Дата/Время</th>' + 
                '<th style="border: 1px solid #ddd; padding: 4px; text-align: left; background-color: #f5f5f5;">Баланс</th>' + 
                '<th style="border: 1px solid #ddd; padding: 4px; text-align: left; background-color: #f5f5f5;">Средства</th>' + 
                '</tr></thead><tbody>';
                
            // Добавляем строки с данными
            if (data.labels && data.labels.length > 0) {
                for (var i = 0; i < data.labels.length; i++) {
                    var formattedDate = this.formatDate(data.labels[i], this.currentPeriod);
                    var balanceValue = data.balance[i] && typeof data.balance[i].y !== 'undefined' ? 
                        new Intl.NumberFormat('ru-RU', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }).format(data.balance[i].y) : '—';
                    
                    var equityValue = data.equity[i] && typeof data.equity[i].y !== 'undefined' ? 
                        new Intl.NumberFormat('ru-RU', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }).format(data.equity[i].y) : '—';
                    
                    htmlContent += '<tr>' + 
                        '<td style="border: 1px solid #ddd; padding: 4px;">' + formattedDate + ' (' + data.labels[i] + ')</td>' + 
                        '<td style="border: 1px solid #ddd; padding: 4px;">' + balanceValue + '</td>' + 
                        '<td style="border: 1px solid #ddd; padding: 4px;">' + equityValue + '</td>' + 
                        '</tr>';
                }
            } else {
                htmlContent += '<tr><td colspan="3" style="border: 1px solid #ddd; padding: 4px; text-align: center;">Нет данных для отображения</td></tr>';
            }
            
            htmlContent += '</tbody></table>';
            
            // Добавляем данные в контейнер
            $chartDataLog.html(htmlContent);
        }
    };

    // Инициализация при загрузке документа
    $(document).ready(function () {
        // V2024.05.07 - Проверяем, находимся ли мы на странице конкурсного счета
        var isContestAccountPage = window.location.href.indexOf('contest_account') > -1;
        
        if (isContestAccountPage) {
            // Специальная инициализация для страницы счета конкурса
            var initAttempts = 0;
            var checkAndInit = function() {
                if ($('#accountChart').length) {
                    try {
                        AccountChart.debug = true; // Включаем отладку на проблемной странице
                        AccountChart.init();
                    } catch (e) {
                        console.warn('Ошибка при инициализации графика:', e);
                    }
                } else if (initAttempts < 5) {
                    initAttempts++;
                    setTimeout(checkAndInit, 500);
                } else {
                    console.warn('График не инициализирован: элемент #accountChart не найден после нескольких попыток');
                }
            };
            
            // Отложенная инициализация с интервалом
            setTimeout(checkAndInit, 300);
        } else {
            // Стандартная инициализация для других страниц
            // V2024.05.06 - Добавляем задержку для полной загрузки всех скриптов и DOM
            setTimeout(function() {
                // Проверяем, существует ли элемент графика в DOM перед инициализацией
                if ($('#accountChart').length) {
                    AccountChart.init();
                } else {
                    console.log('Элемент #accountChart не найден, отложена инициализация графика');
                    // Пробуем еще раз через 500 мс, на случай если элемент загружается асинхронно
                    setTimeout(function() {
                        if ($('#accountChart').length) {
                            AccountChart.init();
                        } else {
                            console.log('Элемент #accountChart не найден после повторной попытки');
                        }
                    }, 500);
                }
            }, 50);
        }
        
        // Добавляем обработчик для кнопки показать/скрыть лог значений v1
        $('#toggleChartDataLog').on('click', function() {
            $('#chartDataLog').toggle();
        });
    });
    
    // Экспортируем объект в глобальную область видимости
    window.AccountChart = AccountChart;
    
    // Тестовая функция для ручного вызова расчета просадки v2
    window.testDrawdown = function() {
        if (window.AccountChart && window.AccountChart.chart) {
            var chartData = window.AccountChart.chart.data;
            var data = {
                labels: chartData.labels,
                balance: [],
                equity: [],
                current_equity: window.AccountChart.chart.data.datasets[1] ? 
                    window.AccountChart.chart.data.datasets[1].data[window.AccountChart.chart.data.datasets[1].data.length - 1] : 0
            };
            
            // Преобразуем данные из формата Chart.js в формат, ожидаемый calculateDrawdown
            if (window.AccountChart.chart.data.datasets[0]) {
                for (var i = 0; i < window.AccountChart.chart.data.datasets[0].data.length; i++) {
                    data.balance.push({
                        y: window.AccountChart.chart.data.datasets[0].data[i]
                    });
                }
            }
            
            if (window.AccountChart.chart.data.datasets[1]) {
                for (var i = 0; i < window.AccountChart.chart.data.datasets[1].data.length; i++) {
                    data.equity.push({
                        y: window.AccountChart.chart.data.datasets[1].data[i]
                    });
                }
            }
            
            window.AccountChart.calculateDrawdown(data);
            console.log('Drawdown recalculated manually v1.0.2');
        } else {
            console.log('AccountChart или график не инициализированы v1.0.2');
        }
    };

    /**
     * Функции для работы с таблицей статистики по инструментам v1.0.2
     */
    $(document).ready(function() {
        // Проверяем наличие таблицы на странице и инициализируем обработчики
        initSymbolTableHandlers();
    });
    
    /**
     * Инициализация обработчиков для таблицы символов v1.3.1
     * Выделено в отдельную функцию для поддержки повторной инициализации
     */
    function initSymbolTableHandlers() {
        console.log('Инициализация обработчиков таблицы символов v1.3.1');
        
        // Обработчик клика по строке символа
        $(document).off('click', '.symbol-row').on('click', '.symbol-row', function() {
            var symbol = $(this).data('symbol');
            var directionRows = $('tr.direction-row[data-parent="' + symbol + '"]');
            var isVisible = directionRows.is(':visible');
            
            console.log('Symbol click v1.3.0: ' + symbol + ' Visible: ' + isVisible);
            
            // Показываем/скрываем строки направлений для данного символа
            if (isVisible) {
                directionRows.hide();
                // Скрываем также все строки сделок для этого символа
                $('tr.trades-row[data-parent="' + symbol + '"]').hide();
                // Сбрасываем состояние иконок направлений
                directionRows.find('.direction-icon').removeClass('expanded');
                $(this).find('.expand-icon').removeClass('expanded');
            } else {
                directionRows.show();
                $(this).find('.expand-icon').addClass('expanded');
            }
        });

        // Обработчик клика по иконке раскрытия символа
        $(document).off('click', '.expand-icon').on('click', '.expand-icon', function(e) {
            // Предотвращаем всплытие события, чтобы не сработал клик по строке
            e.stopPropagation();
            
            // Находим родительскую строку и получаем символ
            var row = $(this).closest('.symbol-row');
            var symbol = row.data('symbol');
            var directionRows = $('tr.direction-row[data-parent="' + symbol + '"]');
            var isVisible = directionRows.is(':visible');
            
            console.log('Icon click v1.3.0: ' + symbol + ' Visible: ' + isVisible);
            
            // Показываем/скрываем строки направлений для данного символа
            if (isVisible) {
                directionRows.hide();
                // Скрываем также все строки сделок для этого символа
                $('tr.trades-row[data-parent="' + symbol + '"]').hide();
                // Сбрасываем состояние иконок направлений
                directionRows.find('.direction-icon').removeClass('expanded');
                $(this).removeClass('expanded');
            } else {
                directionRows.show();
                $(this).addClass('expanded');
            }
        });

        // Обработчик клика по строке направления (buy/sell)
        $(document).off('click', '.direction-row').on('click', '.direction-row', function() {
            var symbol = $(this).data('parent');
            var direction = $(this).hasClass('buy-row') ? 'buy' : 'sell';
            var tradesRows = $('tr.trades-row[data-parent="' + symbol + '"][data-direction="' + direction + '"]');
            var icon = $(this).find('.direction-icon');
            var isVisible = tradesRows.is(':visible');
            
            console.log('Direction row click v1.2.0: ' + symbol + ' ' + direction + ' Visible: ' + isVisible);
            
            if (isVisible) {
                tradesRows.hide();
                icon.removeClass('expanded');
            } else {
                tradesRows.show();
                icon.addClass('expanded');
            }
        });

        // Обработчик клика по иконке направления
        $(document).off('click', '.direction-icon').on('click', '.direction-icon', function(e) {
            e.stopPropagation();
            var row = $(this).closest('.direction-row');
            var symbol = row.data('parent');
            var direction = row.hasClass('buy-row') ? 'buy' : 'sell';
            var tradesRows = $('tr.trades-row[data-parent="' + symbol + '"][data-direction="' + direction + '"]');
            var isVisible = tradesRows.is(':visible');
            
            console.log('Direction icon click v1.2.0: ' + symbol + ' ' + direction + ' Visible: ' + isVisible);
            
            if (isVisible) {
                tradesRows.hide();
                $(this).removeClass('expanded');
            } else {
                tradesRows.show();
                $(this).addClass('expanded');
            }
        });

        // Кнопка "Развернуть все"
        $(document).off('click', '#expandAllSymbols').on('click', '#expandAllSymbols', function() {
            $('.symbol-row .expand-icon').addClass('expanded');
            $('.direction-row').show();
            $('.direction-icon').addClass('expanded');
            $('.trades-row').show();
        });
        
        // Кнопка "Свернуть все"
        $(document).off('click', '#collapseAllSymbols').on('click', '#collapseAllSymbols', function() {
            $('.symbol-row .expand-icon').removeClass('expanded');
            $('.direction-row').hide();
            $('.direction-icon').removeClass('expanded');
            $('.trades-row').hide();
        });
        
        // Фильтрация таблицы
        $(document).off('keyup', '#symbolFilter').on('keyup', '#symbolFilter', function() {
            var value = $(this).val().toLowerCase();
            
            // Сначала скрываем все строки направлений и сделок
            $('.direction-row, .trades-row').hide();
            $('.direction-icon').removeClass('expanded');
            $('.expand-icon').removeClass('expanded');
            
            // Для каждого символа
            $('.symbol-row').each(function() {
                var symbol = $(this).data('symbol').toLowerCase();
                var shouldShow = symbol.includes(value);
                
                $(this).toggle(shouldShow);
                
                // Если символ должен быть отображен и он развернут, показываем его дочерние строки
                if (shouldShow && $(this).find('.expand-icon').hasClass('expanded')) {
                    var directionRows = $('tr.direction-row[data-parent="' + $(this).data('symbol') + '"]');
                    directionRows.show();
                    
                    // Показываем также строки сделок, если соответствующее направление развернуто
                    directionRows.each(function() {
                        if ($(this).find('.direction-icon').hasClass('expanded')) {
                            var direction = $(this).hasClass('buy-row') ? 'buy' : 'sell';
                            $('tr.trades-row[data-parent="' + $(this).data('parent') + '"][data-direction="' + direction + '"]').show();
                        }
                    });
                }
            });
        });
        
        // Сортировка таблицы
        $(document).off('click', '.symbols-table th.sortable').on('click', '.symbols-table th.sortable', function() {
            var table = $(this).parents('table').eq(0);
            var rows = table.find('tr.symbol-row').toArray();
            var dir = 'asc';
            
            // Убираем классы сортировки со всех столбцов
            table.find('th').removeClass('asc desc');
            
            // Определяем направление сортировки
            if ($(this).hasClass('asc')) {
                dir = 'desc';
                $(this).removeClass('asc').addClass('desc');
            } else if ($(this).hasClass('desc')) {
                dir = 'asc';
                $(this).removeClass('desc').addClass('asc');
            } else {
                $(this).addClass('asc');
            }
            
            // Определяем тип сортировки на основе data-атрибута
            var sortType = $(this).data('sort');
            
            // Сортируем строки
            rows.sort(function(a, b) {
                var A, B;
                
                // Извлекаем значение в зависимости от типа сортировки
                if (sortType === 'symbol') {
                    A = $(a).find('td.symbol-name').text().trim();
                    B = $(b).find('td.symbol-name').text().trim();
                    return dir === 'asc' ? A.localeCompare(B) : B.localeCompare(A);
                } else {
                    // Для числовых столбцов извлекаем числа
                    var indexMap = {
                        'trades': 1,
                        'volume': 2,
                        'winrate': 3,
                        'pf': 4,
                        'profit': 5
                    };
                    
                    var index = indexMap[sortType];
                    if (!index) return 0;
                    
                    A = parseFloat($(a).find('td').eq(index).text().replace(/[^\d.-]/g, ''));
                    B = parseFloat($(b).find('td').eq(index).text().replace(/[^\d.-]/g, ''));
                    
                    // Проверяем на NaN
                    if (isNaN(A)) A = 0;
                    if (isNaN(B)) B = 0;
                    
                    return dir === 'asc' ? A - B : B - A;
                }
            });
            
            // Перестраиваем таблицу
            $.each(rows, function(index, row) {
                var symbol = $(row).data('symbol');
                var directionRows = $('tr.direction-row[data-parent="' + symbol + '"]').detach();
                
                table.find('tbody').append(row);
                table.find('tbody').append(directionRows);
                
                // Отображаем направления, если символ был развернут
                if ($(row).find('.expand-icon').hasClass('expanded')) {
                    directionRows.show();
                } else {
                    directionRows.hide();
                }
            });
        });
    }
    
    // Добавляем повторную инициализацию после загрузки AJAX контента
    $(document).on('ajaxComplete', function(event, xhr, settings) {
        if (settings.url.indexOf('get_account_data') !== -1 || 
            settings.url.indexOf('account_chart_data') !== -1) {
            console.log('Обнаружена загрузка данных счета через AJAX, переинициализация обработчиков v1.3.0');
            setTimeout(initSymbolTableHandlers, 500); // Добавляем небольшую задержку
        }
    });
})(jQuery);

