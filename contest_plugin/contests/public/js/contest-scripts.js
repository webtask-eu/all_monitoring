(function($) {
    'use strict';
    
    // Обработчик для отображения/скрытия формы регистрации счета
    $('#show-registration-form').on('click', function() {
        $('#registration-form-container').slideDown(300);
        $('html, body').animate({
            scrollTop: $('#registration-form-container').offset().top - 50
        }, 500);
    });
    
    // Обработчик для скрытия формы регистрации
    $('#cancel-registration').on('click', function(e) {
        e.preventDefault();
        $('#registration-form-container').slideUp(300);
    });
    
    // Обработчик отправки формы регистрации счета
    $('#contest-account-form').on('submit', function(e) {
        e.preventDefault();
        
        // Получаем данные формы
        const contestId = $('#contest_id').val();
        const accountNumber = $('#account_number').val();
        const password = $('#password').val();
        const server = $('#server').val();
        const terminal = $('#terminal').val();
        
        // Проверяем, что все поля заполнены
        if (!accountNumber || !password || !server || !terminal) {
            showFormMessage('Пожалуйста, заполните все поля формы', 'error');
            return false;
        }
        
        // Показываем сообщение о процессе
        showFormMessage('Проверка и регистрация счета...', 'info');
        
        // Отправляем AJAX запрос
        $.ajax({
            url: ftContestData.ajax_url,
            type: 'POST',
            data: {
                action: 'register_contest_account',
                nonce: ftContestData.nonce,
                contest_id: contestId,
                account_number: accountNumber,
                password: password,
                server: server,
                terminal: terminal
            },
            success: function(response) {
                if (response.success) {
                    showFormMessage(response.data.message, 'success');
                    
                    // Перенаправляем пользователя на страницу счета
                    setTimeout(function() {
                        window.location.href = response.data.redirect;
                    }, 1500);
                } else {
                    showFormMessage(response.data.message, 'error');
                }
            },
            error: function() {
                showFormMessage('Произошла ошибка при отправке запроса. Пожалуйста, попробуйте позже.', 'error');
            }
        });
    });
    
    // Функция для отображения сообщений формы
    function showFormMessage(message, type) {
        const $messageContainer = $('#form-message');
        
        // Удаляем предыдущие классы
        $messageContainer.removeClass('success error info');
        
        // Добавляем новый класс в зависимости от типа сообщения
        if (type) {
            $messageContainer.addClass(type);
        }
        
        // Устанавливаем текст и показываем сообщение
        $messageContainer.html(message).slideDown(300);
    }
    
    // Функциональность поиска по таблице участников
    $('#search-participant').on('keyup', function() {
        const searchValue = $(this).val().toLowerCase();
        
        $('#participants-table tbody tr').each(function() {
            const accountNumber = $(this).data('account').toString().toLowerCase();
            
            if (accountNumber.includes(searchValue)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    
    // Если есть параметр подсветки своего счета, фокусируемся на нем
    if ($('#participants-table .current-user-account').length > 0) {
        const $currentUserRow = $('#participants-table .current-user-account');
        
        // Подсвечиваем строку
        $currentUserRow.addClass('highlighted');
        
        // Прокручиваем к этой строке
        setTimeout(function() {
            $('html, body').animate({
                scrollTop: $currentUserRow.offset().top - 100
            }, 500);
        }, 500);
    }
    
    // Сортировка таблицы участников
    $('.sortable').on('click', function() {
        const column = $(this).data('sort');
        const $table = $('#participants-table');
        const $tbody = $table.find('tbody');
        const rows = $tbody.find('tr').toArray();
        
        console.log('Сортировка по колонке:', column); // V2024.04.27 - добавлен лог для отладки
        
        // Определяем порядок сортировки
        const currentOrder = $(this).hasClass('asc') ? 'desc' : 'asc';
        
        // Обновляем классы для индикации сортировки
        $('.sortable').removeClass('asc desc');
        $(this).addClass(currentOrder);
        
        // Сортируем строки
        rows.sort(function(a, b) {
            // Исправленный код V2024.04.27 - находим ячейки с data-value по правильным индексам
            let aValue = 0, bValue = 0;
            
            if (column === 'balance') {
                const aValueCell = $(a).find('td').eq(3); // 4-я ячейка (индекс 3) - Баланс
                const bValueCell = $(b).find('td').eq(3);
                
                // Проверяем, существуют ли ячейки и их data-value
                if (aValueCell.length && aValueCell.data('value') !== undefined) {
                    aValue = parseFloat(aValueCell.data('value'));
                }
                
                if (bValueCell.length && bValueCell.data('value') !== undefined) {
                    bValue = parseFloat(bValueCell.data('value'));
                }
            } else if (column === 'equity') {
                const aValueCell = $(a).find('td').eq(4); // 5-я ячейка (индекс 4) - Средства
                const bValueCell = $(b).find('td').eq(4);
                
                // Проверяем, существуют ли ячейки и их data-value
                if (aValueCell.length && aValueCell.data('value') !== undefined) {
                    aValue = parseFloat(aValueCell.data('value'));
                }
                
                if (bValueCell.length && bValueCell.data('value') !== undefined) {
                    bValue = parseFloat(bValueCell.data('value'));
                }
            }
            
            console.log('Сравнение:', aValue, bValue); // V2024.04.27 - добавлен лог для отладки
            
            if (currentOrder === 'asc') {
                return aValue - bValue;
            } else {
                return bValue - aValue;
            }
        });
        
        // Обновляем номера строк после сортировки
        $.each(rows, function(index, row) {
            $(row).find('td:first').text(index + 1);
        });
        
        // Добавляем отсортированные строки обратно в таблицу
        $tbody.empty().append(rows);
    });

    // Добавляем объект для управления графиком лидеров
    var LeadersChart = {
        // Настройки по умолчанию
        canvasId: 'leadersChart',
        contestId: null,
        nonce: null,
        loadingId: null,
        chartObj: null,
        legendId: null,
        periodSelectId: null,
        topCount: 3, // Количество лидеров для отображения (по умолчанию 3)
        currentPeriod: 'all', // v2.1 - changed default period to 'all' time
        
        // Экземпляр графика
        chart: null,
        
        // Инициализация
        init: function(options) {
            // Объединение опций с настройками по умолчанию
            if (options) {
                if (options.canvasId) this.canvasId = options.canvasId;
                if (options.contestId) this.contestId = options.contestId;
                if (options.nonce) this.nonce = options.nonce;
                if (options.loadingId) this.loadingId = options.loadingId;
                if (options.legendId) this.legendId = options.legendId;
                if (options.periodSelectId) this.periodSelectId = options.periodSelectId;
                if (options.topCount) this.topCount = options.topCount;
            }
            
            // Проверяем наличие необходимых элементов
            if (!$('#' + this.canvasId).length) {
                return;
            }
            
            // Инициализируем обработчики событий
            this.initEvents();
            
            // Загружаем данные
            this.loadChartData(this.currentPeriod);
        },
        
        // Инициализация обработчиков событий
        initEvents: function() {
            var self = this;
            
            // Обработчик для изменения периода
            $('#' + this.periodSelectId).on('change', function() {
                var period = $(this).val();
                self.currentPeriod = period;
                self.loadChartData(period);
            });
        },
        
        // Загрузка данных графика
        loadChartData: function(period) {
            var self = this;
            
            // Исправьте строку показа/скрытия индикатора загрузки
            $('#' + this.loadingId).removeClass('hidden'); // При начале загрузки
            $('#' + this.loadingId).addClass('hidden'); // При завершении

            
            // Если есть старый график, уничтожаем его
            if (this.chart) {
                this.chart.destroy();
            }

            // Выполняем AJAX-запрос
            $.ajax({
                url: ftContestData.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_leaders_chart_data',
                    nonce: this.nonce,
                    contest_id: this.contestId,
                    period: period,
                    top_count: this.topCount
                },
                success: function(response) {
                    // Скрываем индикатор загрузки
                    $('#' + self.loadingId).addClass('hidden');
                    
                    if (response.success) {
                        self.renderChart(response.data);
                    } else {
                        $('#' + self.canvasId).after('<div class="chart-error">Ошибка загрузки данных: ' + response.data.message + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    // Скрываем индикатор загрузки
                    $('#' + self.loadingId).addClass('hidden');
                    $('#' + self.canvasId).after('<div class="chart-error">Ошибка загрузки данных. Пожалуйста, попробуйте позже.</div>');
                }
            });
        },
        
        // Отрисовка графика
        renderChart: function(data) {
            var self = this;
            
            // Очищаем предыдущий график, если он существует
            if (this.chart) {
                this.chart.destroy();
            }

            var ctx = document.getElementById(this.canvasId).getContext('2d');
                        
            // Получаем родительский контейнер для прокрутки
            var $scrollContainer = $('.leaders-chart-scroll-container'); // Изменено
            var $chartContainer = $('.leaders-chart-container'); // Изменено

            // Сбрасываем предыдущие классы
            $chartContainer.removeClass('large-dataset very-large-dataset');

            // Важно: устанавливаем ширину 100% от родительского контейнера
            $chartContainer.css({
                'width': '100%',
                'height': '400px' // Фиксированная высота, можно настроить
            });

            // Скрываем индикатор загрузки
            $('#leaders-chart-loading').hide();

            // Отключаем горизонтальную прокрутку, так как график будет занимать 100% ширины
            $scrollContainer.css('overflow-x', 'hidden');


            // Настройки графика
            var options = {
                type: 'line',  // Тип графика - линейный
                data: {
                    labels: data.labels,  // Метки по оси X (даты)
                    datasets: data.datasets  // Наборы данных для отображения (линии на графике)
                },
                options: {
                    spanGaps: true,  // Соединять линии через пробелы (отсутствующие данные)
                    responsive: true,  // График будет адаптироваться под размер контейнера
                    maintainAspectRatio: false,  // Не сохранять соотношение сторон (можно растягивать)
                    animation: {
                        duration: data.labels.length > 100 ? 0 : 500  // Отключаем анимацию для больших датасетов (>100 точек)
                    },
                    elements: {
                        point: {
                            radius: 3,  // Фиксированный размер точек (в пикселях)
                            hoverRadius: 6,  // Размер точек при наведении курсора (в пикселях)
                            borderWidth: 2,  // Толщина границы точек (в пикселях)
                            backgroundColor: 'white',  // Цвет заливки точек
                            hoverBackgroundColor: 'white',  // Цвет заливки точек при наведении
                            hoverBorderWidth: 3  // Толщина границы точек при наведении (в пикселях)
                        },
                        line: {
                            tension: 0,  // Прямые линии без округлений (0 = прямые, >0 = сглаженные)
                            borderWidth: 2,  // Толщина линий (в пикселях)
                            borderCapStyle: 'round',  // Стиль концов линий (butt, round, square)
                            borderJoinStyle: 'round',  // Стиль соединений линий (round, bevel, miter)
                            fill: false,  // Не заполнять область под линией
                            capBezierPoints: true  // Сглаживание концов линий
                        }
                    },
                    interaction: {
                        mode: 'index',  // Режим взаимодействия - показывать все точки на одной вертикали
                        intersect: false,  // Не требовать точного наведения на точку для показа подсказки
                    },
                    hover: {
                        mode: 'index',  // Режим наведения - подсвечивать все точки на одной вертикали
                        intersect: false  // Не требовать точного наведения на точку для подсветки
                    },
                    plugins: {
                        legend: {
                            display: false  // Не показывать встроенную легенду (используем свою)
                        },
                        tooltip: {
                            mode: 'index',  // Режим подсказки - показывать все точки на одной вертикали
                            intersect: false,  // Не требовать точного наведения на точку
                            backgroundColor: 'rgba(0, 0, 0, 0.7)',  // Цвет фона подсказки
                            titleColor: 'white',  // Цвет заголовка подсказки
                            bodyColor: 'white',  // Цвет текста подсказки
                            borderColor: 'rgba(255, 255, 255, 0.2)',  // Цвет границы подсказки
                            borderWidth: 1,  // Толщина границы подсказки
                            cornerRadius: 4,  // Радиус скругления углов подсказки
                            padding: 10,  // Внутренний отступ подсказки
                            displayColors: true,  // Показывать цветные маркеры в подсказке
                            boxWidth: 10,  // Ширина цветного маркера
                            boxHeight: 10,  // Высота цветного маркера
                            usePointStyle: true,  // Использовать стиль точки для маркера
                            callbacks: {
                                label: function(context) {  // Функция форматирования текста подсказки
                                    var label = context.dataset.label || '';
                                    if (label) label += ': ';
                                    if (context.parsed.y !== null) {
                                        // Форматирование числа с разделителями тысяч и двумя знаками после запятой
                                        label += new Intl.NumberFormat('ru-RU', {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2
                                        }).format(context.parsed.y);
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {  // Настройки оси X
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)',  // Цвет линий сетки
                                drawBorder: false,  // Не рисовать границу оси
                                tickLength: 8  // Длина засечек
                            },
                            ticks: {
                                maxRotation: 0,  // Максимальный угол поворота меток (0 = горизонтально)
                                minRotation: 0,  // Минимальный угол поворота меток
                                autoSkip: true,  // Всегда автоматически пропускать метки 
                                autoSkipPadding: 30, // Минимальное расстояние между метками в пикселях V2023.11.21
                                maxTicksLimit: 15,  // Уменьшенное максимальное количество отображаемых меток V2023.11.21
                                padding: 8,  // Отступ меток от оси
                                font: {
                                    size: 11,  // Размер шрифта меток
                                    family: "'Helvetica Neue', 'Helvetica', 'Arial', sans-serif"  // Шрифт меток
                                },
                                color: 'rgba(0, 0, 0, 0.6)',  // Цвет текста меток
                                callback: function(value, index, ticks) {  // Функция форматирования меток
                                    // Форматирование даты
                                    var label = data.labels[index];
                                    // Примитивное форматирование - можно улучшить
                                    if (label && label.includes('-')) {
                                        var parts = label.split(' ')[0].split('-');
                                        return parts[2] + '.' + parts[1];  // Формат ДД.ММ
                                    }
                                    return label;
                                }
                            }
                        },
                        y: {  // Настройки оси Y
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)',  // Цвет линий сетки
                                drawBorder: false,  // Не рисовать границу оси
                                zeroLineColor: 'rgba(0, 0, 0, 0.1)',  // Цвет линии нуля
                                zeroLineWidth: 1  // Толщина линии нуля
                            },
                            beginAtZero: false,  // Не начинать ось с нуля (автоматический минимум)
                            grace: '5%',  // Добавить 5% отступа сверху и снизу от крайних значений
                            ticks: {
                                padding: 8,  // Отступ меток от оси
                                font: {
                                    size: 11,  // Размер шрифта меток
                                    family: "'Helvetica Neue', 'Helvetica', 'Arial', sans-serif"  // Шрифт меток
                                },
                                color: 'rgba(0, 0, 0, 0.6)',  // Цвет текста меток
                                callback: function(value) {  // Функция форматирования меток
                                    // Форматирование числа с разделителями тысяч без десятичных знаков
                                    return new Intl.NumberFormat('ru-RU', {
                                        minimumFractionDigits: 0,
                                        maximumFractionDigits: 0
                                    }).format(value);
                                }
                            }
                        }
                    },
                    layout: {
                        padding: {  // Отступы графика от краев canvas
                            left: 5,  // Отступ слева
                            right: 10,  // Отступ справа
                            top: 15,  // Отступ сверху
                            bottom: 10  // Отступ снизу
                        }
                    }
                }
            };

            // Цикл для настройки каждого набора данных
            for (var i = 0; i < data.datasets.length; i++) {
                // Обработка отсутствующих значений
                for (var j = 0; j < data.datasets[i].data.length; j++) {
                    var value = data.datasets[i].data[j];
                    // Если значение null, undefined, пустая строка или 0, заменяем на NaN
                    if (value === null || value === undefined || value === '' || value === 0) {
                        data.datasets[i].data[j] = NaN;
                    }
                }
                //data.datasets[i].spanGaps = true;  // Соединять линии через пробелы для каждого набора данных
                
                data.datasets[i].tension = 0;  // Прямые линии без округлений
                
                data.datasets[i].pointRadius = 3;  // Фиксированный размер точек (в пикселях)
                
                data.datasets[i].pointBackgroundColor = 'white';  // Белый фон точек
                
                data.datasets[i].pointBorderColor = data.datasets[i].borderColor;  // Цвет границы точек совпадает с цветом линии
                
                data.datasets[i].pointBorderWidth = 2;  // Толщина границы точек (в пикселях)
                
                // Настройки при наведении
                data.datasets[i].pointHoverRadius = 6;  // Размер точек при наведении (в пикселях)
                data.datasets[i].pointHoverBackgroundColor = 'white';  // Цвет заливки точек при наведении
                data.datasets[i].pointHoverBorderColor = data.datasets[i].borderColor;  // Цвет границы точек при наведении
                data.datasets[i].pointHoverBorderWidth = 3;  // Толщина границы точек при наведении (в пикселях)
                
                data.datasets[i].borderWidth = 2;  // Толщина линии (в пикселях)
            }

            // Создаем график
            this.chart = new Chart(ctx, options);
            
            // Обновляем легенду
            this.updateLegend(data.datasets);
            
            // Прокручиваем контейнер к началу графика
            setTimeout(function() {
                $scrollContainer.scrollLeft(0);
            }, 100);
        },
        
        // Обновление легенды
        updateLegend: function(datasets) {
            var $legendContainer = $('#' + this.legendId);
            var self = this;
            $legendContainer.empty();
            
            // Создаем HTML для каждого элемента легенды
            for (var i = 0; i < datasets.length; i++) {
                var dataset = datasets[i];
                var legendItem = $('<div class="legend-item">' +
                                  '<span class="legend-color" style="background-color:' + dataset.borderColor + '"></span>' +
                                  '<span class="legend-label">' + dataset.label + '</span>' +
                                  '</div>');
                                  
                // Обработчик клика для скрытия/показа трейдера на графике
                legendItem.on('click', (function(index) {
                    return function() {
                        // Переключаем видимость датасета
                        const visibility = self.chart.isDatasetVisible(index);
                        if (visibility) {
                            self.chart.hide(index);
                            $(this).addClass('legend-hidden');
                        } else {
                            self.chart.show(index);
                            $(this).removeClass('legend-hidden');
                        }
                    };
                })(i));
                
                $legendContainer.append(legendItem);
            }
        }        
    };
    
    // Инициализация графика лидеров, если есть нужный элемент на странице
    if ($('#leadersChart').length) {
        var leadersChartNonce = $('#leadersChart').data('nonce');
        var contestId = $('#leadersChart').data('contest-id');
        var topCount = $('#leadersChart').data('top-count') || 3;
        
        LeadersChart.init({
            canvasId: 'leadersChart',
            contestId: contestId,
            nonce: leadersChartNonce,
            loadingId: 'leaders-chart-loading',
            legendId: 'leadersChartLegend',
            periodSelectId: 'leaders_chart_period',
            topCount: topCount
        });
    }    

    // Обработчик для каскадных выпадающих списков брокер -> платформа -> сервер
    $('#broker').on('change', function() {
        const brokerId = $(this).val();
        
        if (brokerId) {
            // Включаем выбор платформы и показываем индикатор загрузки
            $('#platform').prop('disabled', true).html('<option value="">Загрузка платформ...</option>');
            
            // Получаем список платформ для выбранного брокера
            $.ajax({
                url: ftContestData.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_broker_platforms',
                    nonce: ftContestData.nonce,
                    broker_id: brokerId
                },
                success: function(response) {
                    if (response.success) {
                        // Обновляем выпадающий список платформ
                        let options = '<option value="">Выберите платформу</option>';
                        $.each(response.data, function(index, platform) {
                            options += `<option value="${platform.id}">${platform.name}</option>`;
                        });
                        $('#platform').html(options).prop('disabled', false);
                    } else {
                        $('#platform').html('<option value="">Ошибка загрузки платформ</option>');
                        console.error('Ошибка при загрузке платформ:', response.data.message);
                    }
                },
                error: function() {
                    $('#platform').html('<option value="">Ошибка загрузки платформ</option>');
                    console.error('Ошибка при выполнении AJAX запроса');
                }
            });
            
            // Сбрасываем выбор сервера
            $('#server').html('<option value="">Сначала выберите платформу</option>').prop('disabled', true);
        } else {
            // Сбрасываем выбор платформы и сервера
            $('#platform').html('<option value="">Сначала выберите брокера</option>').prop('disabled', true);
            $('#server').html('<option value="">Сначала выберите платформу</option>').prop('disabled', true);
        }
    });
    
    // При выборе платформы
    $('#platform').on('change', function() {
        const platformId = $(this).val();
        const brokerId = $('#broker').val();
        
        if (platformId && brokerId) {
            // Включаем выбор сервера и показываем индикатор загрузки
            $('#server').prop('disabled', true).html('<option value="">Загрузка серверов...</option>');
            
            // Получаем список серверов для выбранного брокера и платформы
            $.ajax({
                url: ftContestData.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_broker_servers',
                    nonce: ftContestData.nonce,
                    broker_id: brokerId,
                    platform_id: platformId
                },
                success: function(response) {
                    if (response.success) {
                        // Обновляем выпадающий список серверов
                        let options = '<option value="">Выберите сервер</option>';
                        $.each(response.data, function(index, server) {
                            options += `<option value="${server.server_address}">${server.name}</option>`;
                        });
                        $('#server').html(options).prop('disabled', false);
                    } else {
                        $('#server').html('<option value="">Ошибка загрузки серверов</option>');
                        console.error('Ошибка при загрузке серверов:', response.data.message);
                    }
                },
                error: function() {
                    $('#server').html('<option value="">Ошибка загрузки серверов</option>');
                    console.error('Ошибка при выполнении AJAX запроса');
                }
            });
        } else {
            // Сбрасываем выбор сервера
            $('#server').html('<option value="">Сначала выберите платформу</option>').prop('disabled', true);
        }
    });

})(jQuery);
