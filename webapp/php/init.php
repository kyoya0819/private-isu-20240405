<?php // 初回環境構築時はこれを実行してください。
require "vendor/autoload.php";

use Libs\Db;

$pdo = new Db();


// テーブル定義を取得
$q = $pdo->query("SHOW COLUMNS FROM posts");
$res = $q->fetchAll(PDO::FETCH_ASSOC);
$posts_columns = array_map(fn ($v) => $v["Field"], $res);

$q = $pdo->query("SHOW COLUMNS FROM comments");
$res = $q->fetchAll(PDO::FETCH_ASSOC);
$comments_columns = array_map(fn ($v) => $v["Field"], $res);


// すでに更新後のテーブル構造の場合処理を終了
if (
    in_array("image", $posts_columns)
    && !in_array("imgdata", $posts_columns)
    && !in_array("mime", $posts_columns)
    && in_array("created_at_index", $posts_columns)
    && in_array("user_id_index", $comments_columns)
) {
    echo "Complete";
    exit(0);
}


if (!in_array("image", $posts_columns)) {
    // テーブルに画像のパスを格納する項目を追加
    $pdo->exec("ALTER TABLE posts ADD image VARCHAR(100) AFTER user_id");

    // 画像のidを取得
    $q = $pdo->query("SELECT id FROM posts");
    $post_ids = array_map(fn ($v) => $v["id"], $q->fetchAll(PDO::FETCH_ASSOC));

    // 保存先のディレクトリの定義
    $image_dir = dirname(__FILE__) . "/public/image";
    if (!file_exists($image_dir))
        mkdir($image_dir);

    foreach ($post_ids as $post_id) {
        $post = $pdo->fetchFirst("SELECT mime, imgdata FROM posts WHERE id = ?", $post_id);

        $filename = (string) $post_id;
        if (str_contains($post["mime"], "jpeg"))
            $filename .= ".jpg";
        if (str_contains($post["mime"], "png"))
            $filename .= ".png";
        if (str_contains($post["mime"], "gif"))
            $filename .= ".gif";

        file_put_contents($image_dir . "/" . $filename, $post["imgdata"]);

        $q = $pdo->prepare("UPDATE posts SET image = :image WHERE id = :post_id");
        $q->execute(["image" => $filename, "post_id" => $post_id]);
    }

    $pdo->exec("ALTER TABLE posts MODIFY COLUMN image VARCHAR(100) NOT NULL");
}


if (in_array("imgdata", $posts_columns))
    // `imagedata`カラムを削除
    $pdo->exec("ALTER TABLE posts DROP COLUMN imgdata");


if (in_array("mime", $posts_columns))
    // `mime`カラムを削除
    $pdo->exec("ALTER TABLE posts DROP COLUMN mime");


if (!in_array("created_at_index", $posts_columns))
    // postsテーブルに`created_at`でインデックスを貼る
    $pdo->exec("ALTER TABLE posts ADD INDEX created_at_index(created_at DESC)");


if (!in_array("user_id_index", $comments_columns))
    // commentsテーブルに`user_id_index`でインデックスを貼る
    $pdo->exec("ALTER TABLE comments ADD INDEX user_id_index(user_id)");


echo "Complete";
exit(0);