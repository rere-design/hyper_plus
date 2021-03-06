define(function () {
    if(!window['BX24']) return;

    var change = false;
    // var bxPlace = document.getElementById('b24placement');
    // if (bxPlace && bxPlace.dataset['options']) {
    //     var params = JSON.parse(bxPlace.dataset.options), scriptList = document.getElementById('js_script_list');
    // }
    // localStorage.current_script = params.script;
    $(function () {
        var bx24Data = $('#b24placement'),
            info = BX24.placement.info() || bx24Data.data(),
            scriptList = document.getElementById('js_script_list'),
            hs = window.hyperscript,
            user;

        bx24Data.data('type', 'CALL_CARD');

        var commentButton = $('<button class="button-input">Комментарий</div>').css({position: 'absolute', right: 0}),
            commentBlock = $('<div class="comment-info"></div>').css({
                position: 'absolute',
                top: 0,
                right: 0,
                left: 0,
                bottom: 0,
                background: 'white'
            }),
            commentField = $('<textarea autofocus>').css({width: '100%', height: '99%'}),
            commentClose = $('<button class="button-input">Скрыть</div>').css({
                position: 'absolute',
                right: 5,
                bottom: 10
            });

        commentBlock.hide().appendTo('.view_box_sidebar').append(commentField).append(commentClose);
        commentButton.prependTo('.view_box_sidebar').click(function () {
            commentBlock.show();
        });
        commentClose.click(function () {
            commentBlock.hide();
        });


        if (info.options && info.options.script) {
            $(document).ajaxSuccess(function () {
                if(scriptList.value && !change)
                    hs.scriptContainer.val(info.options.script);
                if (window['hyperscript'] && scriptList.value && !change) {
                    var interval = setInterval(function () {
                        if ($.app.script.view.is_call_in && hs.scriptContainer.val() === info.options.script) {
                            clearInterval(interval);
                            setTimeout(function(){
                                change = true;
                            }, 5000);
                            return;
                        }
                        hs.ready();
                        setTimeout(function(){
                            hs.scriptContainer.change();
                            hs.searchInput.change();
                        }, 1000);
                    }, 10);
                }
            }).ajaxSuccess(function (event, response, params, result) {
                if (params.url === '/api/bitrix/auth' && !user) {
                    BX24.callMethod('user.get', {'EMAIL': result.response.username},
                        function (result) {
                            if (result.answer) result = result.answer;
                            result = result.result;
                            if (!result.length) {
                                BX24.callMethod('user.current', {},
                                    function (result) {
                                        if (result.answer) result = result.answer;
                                        user = result.result;
                                    }
                                );
                            } else
                                user = result[0];
                        }
                    );
                }

                if (params.url === '/api/bitrix/save_log') {
                    if (info.options) {
                        var types = {
                            CONTACT: 3,
                            DEAL: 2,
                            COMPANY: 4,
                            LEAD: 1
                        }, message = 'Завершен скрипт: ' + $(':selected', '#js_script_list').text() + '\n' +
                            'Дата завершения: ' + (new Date()).toLocaleString() + '\n' +
                            'Оператор: ' + user.NAME + ' ' + user.LAST_NAME;
                        if (commentField.val()) message += '\n\n' + commentField.val();
                        BX24.callMethod('crm.livefeedmessage.add',
                            {
                                fields: {
                                    POST_TITLE: 'Завершение скрипта',
                                    MESSAGE: message,
                                    ENTITYTYPEID: types[info.options.CRM_ENTITY_TYPE],
                                    ENTITYID: info.options.CRM_ENTITY_ID
                                }
                            },
                            function (result) {
                                if (result.answer) result = result.answer;
                                if (result.result) goBack();
                                else alert('ОШИБКА: ' + result.error);
                            }
                        );
                    } else {
                        goBack();
                    }

                }
            });
        }

        function goBack() {
            var f = $('<form>').attr('action', info.options.back).attr('target', '_parent');
            f.appendTo(document.body);
            f.submit();
        }
    });


});