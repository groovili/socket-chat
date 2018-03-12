var socket = new WebSocket("ws://127.0.0.1:8080");

socket.onopen = function() {
    console.log('Connection successful');
};

socket.onclose = function(event) {
    if (event.wasClean) {
        console.log('Connection closed.');
    } else {
        console.log('Connection killed:(');
    }
    console.log(event.code + event.reason);
};

socket.onmessage = function(event) {
    var list = document.getElementById('list');
    var p = document.createElement("p");
    var node = document.createTextNode(event.data);
    p.appendChild(node);
    list.appendChild(p);
};

socket.onerror = function(error) {
    console.log(error.message);
};

var button = document.getElementById('send');
button.onclick = function (ev) {
    var text = document.getElementById('message-box').value;
    if(text.length > 0){
        socket.send(text);
    }
}