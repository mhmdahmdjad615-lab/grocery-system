document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        document.querySelectorAll('.alert').forEach(function (a) {
            var alert = bootstrap.Alert.getOrCreateInstance(a);
            if (alert) alert.close();
        });
    }, 5000);
});
