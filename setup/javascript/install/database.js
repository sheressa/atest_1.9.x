$(document).ready(function() {
    $('#database-config').validate({submitHandler: function(){submit_db()}});
});

function submit_db()
{
    $.post('index.php?sec=install&js=dblogin', {
        database_user: $('#database-user').val(),
        database_password: $('#database-password').val(),
        database_host: $('#database-host').val(),
        database_port: $('#database-port').val()
    }, function(data) {
        if (data.error != '') {
            $('#message-body').html(data.error);
            $('.alert').show();
            console.log(data.error);
        } else {
            console.log(data.content);
        }
    }, 'json');
    return false;
}