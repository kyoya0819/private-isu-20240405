<?php

use Libs\Db;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;
use Ramsey\Uuid\Uuid;


$app = AppFactory::create();

$app->get("/initialize", function (Request $request, Response $response) {
    /** @var $db Db */
    $db = $this->get("db");

    $sql = [];
    $sql[] = "DELETE FROM users WHERE id > 1000";
    $sql[] = "DELETE FROM posts WHERE id > 10000";
    $sql[] = "DELETE FROM comments WHERE id > 100000";
    $sql[] = "UPDATE users SET del_flg = 0";
    $sql[] = "UPDATE users SET del_flg = 1 WHERE id % 50 = 0";
    foreach($sql as $s) {
        $db->query($s);
    }

    return $response;
});

$app->get("/login", function (Request $request, Response $response) {
    if ($this->get("helper")->get_session_user() !== null) {
        return redirect($response, "/", 302);
    }
    return $this->get("view")->render($response, "login.php", [
        "me" => null,
        "flash" => $this->get("flash")->getFirstMessage("notice"),
    ]);
});

$app->post("/login", function (Request $request, Response $response) {
    if ($this->get("helper")->get_session_user() !== null) {
        return redirect($response, "/", 302);
    }

    $params = $request->getParsedBody();
    $user = $this->get("helper")->try_login($params["account_name"], $params["password"]);

    if ($user) {
        $_SESSION["user"] = [
            "id" => $user["id"],
        ];
        return redirect($response, "/", 302);
    } else {
        $this->get("flash")->addMessage("notice", "アカウント名かパスワードが間違っています");
        return redirect($response, "/login", 302);
    }
});

$app->get("/register", function (Request $request, Response $response) {
    if ($this->get("helper")->get_session_user() !== null) {
        return redirect($response, "/", 302);
    }
    return $this->get("view")->render($response, "register.php", [
        "me" => null,
        "flash" => $this->get("flash")->getFirstMessage("notice"),
    ]);
});


$app->post("/register", function (Request $request, Response $response) {
    /** @var $db Db */
    $db = $this->get("db");

    if ($this->get("helper")->get_session_user()) {
        return redirect($response, "/", 302);
    }

    $params = $request->getParsedBody();
    $account_name = $params["account_name"];
    $password = $params["password"];

    $validated = validate_user($account_name, $password);
    if (!$validated) {
        $this->get("flash")->addMessage("notice", "アカウント名は3文字以上、パスワードは6文字以上である必要があります");
        return redirect($response, "/register", 302);
    }

    $user = $db->fetchFirst("SELECT 1 FROM users WHERE `account_name` = ?", $account_name);
    if ($user) {
        $this->get("flash")->addMessage("notice", "アカウント名がすでに使われています");
        return redirect($response, "/register", 302);
    }

    $ps = $db->prepare("INSERT INTO `users` (`account_name`, `passhash`) VALUES (?,?)");
    $ps->execute([
        $account_name,
        calculate_passhash($account_name, $password)
    ]);
    $_SESSION["user"] = [
        "id" => $db->lastInsertId(),
    ];
    return redirect($response, "/", 302);
});

$app->get("/logout", function (Request $request, Response $response) {
    unset($_SESSION["user"]);
    return redirect($response, "/", 302);
});

$app->get("/", function (Request $request, Response $response) {
    /** @var $db Db */
    $db = $this->get("db");
    $me = $this->get("helper")->get_session_user();

    $sql = <<<EOF
SELECT 
    posts.id AS id,
    posts.user_id AS user_id,
    posts.image AS image,
    posts.body AS body,
    posts.created_at AS created_at
FROM posts
    LEFT JOIN users
        ON posts.user_id = users.id 
WHERE 
    users.del_flg = 0 
ORDER BY `created_at` DESC
EOF;

    $ps = $db->prepare($sql . " LIMIT " . POSTS_PER_PAGE);
    $ps->execute();
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get("helper")->make_posts($results);

    return $this->get("view")->render($response, "index.php", [
        "posts" => $posts,
        "me" => $me,
        "flash" => $this->get("flash")->getFirstMessage("notice"),
    ]);
});

$app->get("/posts", function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $max_created_at = $params["max_created_at"] ?? null;

    $sql = <<<EOF
SELECT 
    posts.id AS id,
    posts.user_id AS user_id,
    posts.image AS image,
    posts.body AS body,
    posts.created_at AS created_at
FROM posts
    LEFT JOIN users
        ON posts.user_id = users.id 
WHERE 
    users.del_flg = 0
  AND
    posts.created_at <= ?
ORDER BY `created_at` DESC
EOF;
    $db = $this->get("db");
    $ps = $db->prepare($sql . " LIMIT " . POSTS_PER_PAGE);
    $ps->execute([$max_created_at === null ? null : $max_created_at]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get("helper")->make_posts($results);

    return $this->get("view")->render($response, "posts.php", ["posts" => $posts]);
});

$app->get("/posts/{id}", function (Request $request, Response $response, $args) {
    $db = $this->get("db");
    $ps = $db->prepare("SELECT * FROM `posts` WHERE `id` = ?");
    $ps->execute([$args["id"]]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get("helper")->make_posts($results, ["all_comments" => true]);

    if (count($posts) == 0) {
        $response->getBody()->write("404");
        return $response->withStatus(404);
    }

    $post = $posts[0];

    $me = $this->get("helper")->get_session_user();

    return $this->get("view")->render($response, "post.php", ["post" => $post, "me" => $me]);
});

$app->post("/", function (Request $request, Response $response) {
    $me = $this->get("helper")->get_session_user();

    if ($me === null) {
        return redirect($response, "/login", 302);
    }

    $params = $request->getParsedBody();
    if ($params["csrf_token"] !== session_id()) {
        $response->getBody()->write("422");
        return $response->withStatus(422);
    }

    if ($_FILES["file"]) {
        $filename = Uuid::uuid4()->toString();
        // 投稿のContent-Typeからファイルのタイプを決定する
        if (strpos($_FILES["file"]["type"], "jpeg") !== false) {
            $filename .= ".jpg";
        } elseif (strpos($_FILES["file"]["type"], "png") !== false) {
            $filename .= ".png";
        } elseif (strpos($_FILES["file"]["type"], "gif") !== false) {
            $filename .= ".gif";
        } else {
            $this->get("flash")->addMessage("notice", "投稿できる画像形式はjpgとpngとgifだけです");
            return redirect($response, "/", 302);
        }

        if (strlen(file_get_contents($_FILES["file"]["tmp_name"])) > UPLOAD_LIMIT) {
            $this->get("flash")->addMessage("notice", "ファイルサイズが大きすぎます");
            return redirect($response, "/", 302);
        }

        $image_dir = dirname(__FILE__) . "/../public/image";
        file_put_contents($image_dir . "/" . $filename, file_get_contents($_FILES["file"]["tmp_name"]));

        $db = $this->get("db");
        $ps = $db->prepare("INSERT INTO `posts` (`user_id`, `image`, `body`) VALUES (?,?,?)");
        $ps->execute([
            $me["id"],
            $filename,
            $params["body"]
        ]);
        $pid = $db->lastInsertId();

        return redirect($response, "/posts/{$pid}", 302);
    } else {
        $this->get("flash")->addMessage("notice", "画像が必須です");
        return redirect($response, "/", 302);
    }
});

$app->post("/comment", function (Request $request, Response $response) {
    $me = $this->get("helper")->get_session_user();

    if ($me === null) {
        return redirect($response, "/login", 302);
    }

    $params = $request->getParsedBody();
    if ($params["csrf_token"] !== session_id()) {
        $response->getBody()->write("422");
        return $response->withStatus(422);
    }

    if (preg_match("/\A[0-9]+\z/", $params["post_id"]) == 0) {
        $response->getBody()->write("post_idは整数のみです");
        return $response;
    }
    $post_id = $params["post_id"];

    $query = "INSERT INTO `comments` (`post_id`, `user_id`, `comment`) VALUES (?,?,?)";
    $ps = $this->get("db")->prepare($query);
    $ps->execute([
        $post_id,
        $me["id"],
        $params["comment"]
    ]);

    return redirect($response, "/posts/{$post_id}", 302);
});

$app->get("/admin/banned", function (Request $request, Response $response) {
    $me = $this->get("helper")->get_session_user();

    if ($me === null) {
        return redirect($response, "/login", 302);
    }

    if ($me["authority"] == 0) {
        $response->getBody()->write("403");
        return $response->withStatus(403);
    }

    $db = $this->get("db");
    $ps = $db->prepare("SELECT * FROM `users` WHERE `authority` = 0 AND `del_flg` = 0 ORDER BY `created_at` DESC");
    $ps->execute();
    $users = $ps->fetchAll(PDO::FETCH_ASSOC);

    return $this->get("view")->render($response, "banned.php", ["users" => $users, "me" => $me]);
});

$app->post("/admin/banned", function (Request $request, Response $response) {
    $me = $this->get("helper")->get_session_user();

    if ($me === null) {
        return redirect($response, "/login", 302);
    }

    if ($me["authority"] == 0) {
        $response->getBody()->write("403");
        return $response->withStatus(403);
    }

    $params = $request->getParsedBody();
    if ($params["csrf_token"] !== session_id()) {
        $response->getBody()->write("422");
        return $response->withStatus(422);
    }

    $uid_placeholder = implode(",", array_pad([], count($params["uid"]), "?"));

    /* @var $db Db */
    $db = $this->get("db");
    $db->exec("UPDATE users SET del_flg = 1 WHERE id IN ($uid_placeholder)");

    return redirect($response, "/admin/banned", 302);
});

$app->get("/@{account_name}", function (Request $request, Response $response, $args) {
    /** @var $db Db */
    $db = $this->get("db");
    $user = $db->fetchFirst("SELECT * FROM `users` WHERE `account_name` = ? AND `del_flg` = 0", $args["account_name"]);

    if ($user === false) {
        $response->getBody()->write("404");
        return $response->withStatus(404);
    }

    $ps = $db->prepare("SELECT `id`, `user_id`, `body`, `created_at`, `image` FROM `posts` WHERE `user_id` = ? ORDER BY `created_at` DESC");
    $ps->execute([$user["id"]]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get("helper")->make_posts($results);

    $comment_count = $db->fetchFirst("SELECT COUNT(*) AS count FROM `comments` WHERE `user_id` = ?", $user["id"])["count"];

    $ps = $db->prepare("SELECT `id` FROM `posts` WHERE `user_id` = ?");
    $ps->execute([$user["id"]]);
    $post_ids = array_column($ps->fetchAll(PDO::FETCH_ASSOC), "id");
    $post_count = count($post_ids);

    $commented_count = 0;
    if ($post_count > 0) {
        $placeholder = implode(",", array_fill(0, count($post_ids), "?"));
        $commented_count = $db->fetchFirst("SELECT COUNT(*) AS count FROM `comments` WHERE `post_id` IN ({$placeholder})", ...$post_ids)["count"];
    }

    $me = $this->get("helper")->get_session_user();

    return $this->get("view")->render($response, "user.php", ["posts" => $posts, "user" => $user, "post_count" => $post_count, "comment_count" => $comment_count, "commented_count"=> $commented_count, "me" => $me]);
});

$app->run();