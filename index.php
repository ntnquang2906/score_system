<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Hệ thống đánh giá KH&CN</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-login-link {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #667eea;
            color: white;
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 5px;
            font-size: 13px;
            transition: background-color 0.3s;
        }
        .admin-login-link:hover {
            background-color: #764ba2;
        }
        body {
            position: relative;
        }
    </style>
</head>

<body>
    <a href="login.php" class="admin-login-link">🔐 Lãnh đạo / Quản trị</a>
    <h1>Hệ thống đánh giá tổ chức KH&CN</h1>
    <form action="process.php" method="POST" enctype="multipart/form-data" onsubmit="return validateForm()">
        <label>Tên đơn vị đánh giá:</label>
        <input type="text" name="organization_name" required>
        <h3>Chọn chức năng:</h3>
        <div id="function-checkboxes">
            <label><input type="checkbox" value="basic"> Nghiên cứu cơ bản</label>
            <label><input type="checkbox" value="applied"> Nghiên cứu ứng dụng</label>
            <label><input type="checkbox" value="tech"> Phát triển công nghệ</label>
            <label><input type="checkbox" value="policy"> Nghiên cứu chính sách KT-XH</label>
        </div>
        <div id="hidden-inputs"></div>
        <div id="form-area"></div>
        <button type="submit">Tính điểm & Lưu Excel</button>
    </form>
    <script src="script.js?v=5"></script>
</body>

</html>