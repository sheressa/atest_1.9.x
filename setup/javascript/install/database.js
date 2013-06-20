$(document).ready(function() {
    $('#database-config').submit(function(){$('alert').hide();});
    $('#database-config').validate({submitHandler: function(){submit_db()}});
});

function submit_db()
{
    dbtype = $('input[name="database_type"]:checked').val();

    $.post('index.php?sec=install&js=dblogin', {
        database_type: dbtype,
        database_user: $('#database-user').val(),
        database_password: $('#database-password').val(),
        database_host: $('#database-host').val(),
        database_port: $('#database-port').val()
    }, function(data) {
        if (data.error !== undefined) {
            $('#message-body').html(data.error);
            $('.alert').slideDown();
            console.log(data.error);
        } else {
            console.log(data.content);
        }
    }, 'json');
    return false;
}
