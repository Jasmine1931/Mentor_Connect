function openModal(id) {
    document.getElementById("mentor_id").value = id;
    document.getElementById("requestModal").style.display = "block";
}

function closeModal() {
    document.getElementById("requestModal").style.display = "none";
}