# 実画面・提出用素材

## 0.3.0-alpha の提出用画面

2026-09-20 JST、専用コース11「データで考える、わたしたちの暮らし」で撮影した実画面7枚。架空の学生版441（青木ひなた・日本語）、教師版442（佐藤由紀・日本語）、学生版443（高橋湊・英語）を通常画面で要求し、通常cronの完成後に取得・展開した。検証マーカーを含む旧コース10はそのまま保持している。

| 画像 | 版・主体・示せること |
|---|---|
| [01 学習記録のホーム](screenshots/0.3/01-offline-home-ja.png) | 学生441。展開したHTMLから、コースの教材と学習成果へ入る |
| [02 提出とフィードバック](screenshots/0.3/02-assignment-ja.png) | 学生441。考察本文・添付・評価・教師の講評を一緒に読み返す |
| [03 保存した小テスト](screenshots/0.3/03-quiz-ja.png) | 学生441。保存済み回答・正誤・数式・公開フィードバック。既存問題バンクの基本問題を利用 |
| [04 英語のホーム](screenshots/0.3/04-offline-home-en.png) | 学生443。Tombの表示は英語、元の日本語コース名・教材本文は保持 |
| [05 完成通知と取得](screenshots/0.3/05-download-and-notification.png) | 学生441。Moodle標準の完成通知と本人のダウンロード・受取確認操作。通知一覧には過去の試験通知も残る |
| [06 教師の保存記録](screenshots/0.3/06-teacher-assignment-ja.png) | 教師442。採点権限で閲覧できる参加者別の提出・評価を保存。投稿者欄は仮名が標準 |
| [07 未受領を追う](screenshots/0.3/07-receipt-management.png) | 管理画面。専用コース・収集完了・未受領で絞り込んだ3件と同条件のCSV。本人取得前に撮影し、その後441で受取確認を実施 |

01〜06は幅1440pxの表示範囲（06のみ高さ1200px）、07は一覧領域を直接撮影した。モックや画像加工で機能を補っていない。管理撮影時の専用教師への一時権限は解除済み。

操作説明は「コースと言語を選んで作成 → Moodleの完成通知を開く → ZIPを保存してすべて展開 → index.htmlから課題・講評・受験を確認 → Moodleへ戻り受取確認」の順に行う。教師の作成代行では、学生本人がZIP準備と取得を行う。

成果物：`build/tomb-0.3.0-alpha.zip`（Moodle導入用）、`build/demo-learner-ja-0.3.zip` / `build/demo-teacher-ja-0.3.zip` / `build/demo-other-en-0.3.zip`（学習記録）、`build/demo-*-assignment-print-0.3.pdf`（印刷例）。学習記録ZIP・認証情報はGit対象外。[検証結果](VALIDATION.md)に版ごとのSHA-256を記録した。

## 0.2.0-alpha の追加画面

同日09:01〜09:05 JSTに、通常の画面操作で生成した学生版36・教師版35を撮影。追加11枚。ブラウザ幅1440px、小画面390px。オフライン画像は実際にダウンロードしたZIPを展開して表示した。

| 画像 | 説明 |
|---|---|
| [一括受付の結果](screenshots/0.2/01-batch-results.png) | 利用者別の成功・受付間隔による失敗を表示。成功した学生版34は本人が取得する |
| [学生の受け取り](screenshots/0.2/02-learner-ready.png) / [教師の受け取り](screenshots/0.2/03-teacher-ready.png) | 通常cronで準備・検証された新しい記録 |
| [学生のホーム](screenshots/0.2/04-learner-offline-home.png) / [教師のホーム](screenshots/0.2/04-teacher-offline-home.png) | オフラインの入口 |
| [学生の成績](screenshots/0.2/05-learner-grades.png) / [教師の成績](screenshots/0.2/05-teacher-grades.png) | 学生には公開成績、権限のある教師には未公開評価も含める。非公開ラベルを表示 |
| [学生の小テスト](screenshots/0.2/06-learner-quiz.png) / [教師の小テスト](screenshots/0.2/06-teacher-quiz.png) | 保存済み受験とローカルの数式表示 |
| [学生の小画面](screenshots/0.2/07-learner-mobile-assignment.png) / [教師の小画面](screenshots/0.2/07-teacher-mobile-assignment.png) | 課題ページを390px幅で表示 |

`TOMB_PRIVATE_*` は非公開評価が学生版へ混入していないことを検証する架空データの目印。実在者の秘密情報ではない。コンペでは学生ホーム・受け取りを主画像とし、教師の権限差を説明する場合に成績の対比を使える。

成果物は `build/tomb-0.2.0-alpha.zip`（導入用）、`build/demo-learner-0.2.zip` / `build/demo-teacher-0.2.zip`（学習記録の実物）、`build/demo-learner-assignment-print-0.2.pdf` / `build/demo-teacher-assignment-print-0.2.pdf`（印刷例）。旧版の成果物も別名で保持する。生成物と認証情報はGit対象外。

## 0.1.0-alpha の記録

撮影日：2026-09-20 JST。0.1.0-alpha の実装を実際に操作して撮影した。架空の学生・教師を使用し、パスワード・セッション・鍵は含めていない。モック画面や画像生成による画面ではない。

オフライン画面は専用コース10、学生50の**版11**を通常の取得経路で保存・展開したもの。デスクトップは幅1440px、小画面は幅390px。PNGは全ページ撮影のため、縦長の画像を含む。

コンペの中心には **03（ポータル）、05（学習成果と講評）、07（議論）、08（小テスト）、02（受け取り）** の組み合わせが使える。画像の説明は以下の内容に合わせる。

| 画像 | 説明・示せること |
|---|---|
| [01 学生の選択](screenshots/01-student-selection.png) | 本人が保存するコースを選び、生成を依頼する入口 |
| [02 受け取り](screenshots/02-student-ready.png) | バックグラウンドで検証済みZIPを用意し、本人へ渡す画面 |
| [03 オフラインのホーム](screenshots/03-offline-home.png) | Moodle停止後も、展開したHTMLから学びの記録へ入れる |
| [04 コース目次](screenshots/04-offline-course.png) | 教材と活動を授業のまとまりで読み返す |
| [05 課題と講評](screenshots/05-assignment.png) | 本人の提出本文・添付と、公開された教師のコメント・添付 |
| [06 成績](screenshots/06-grades.png) | 本人に公開された評価を保存。非公開要素を含む集計は注記 |
| [07 フォーラム](screenshots/07-forum.png) | 閲覧可能な議論の流れを残し、他者の投稿者欄を仮名表示 |
| [08 小テスト](screenshots/08-quiz.png) | 保存済み受験の回答・正誤・数式・公開フィードバック。5形式 |
| [09 保存内容の注記](screenshots/09-omissions.png) | 保存対象と、未完了受験など出力しなかった内容を示す |
| [10 教師](screenshots/10-teacher.png) | 教師版と学生版の作成代行。代行した学生版の取得リンクはない |
| [11 管理](screenshots/11-administration.png) | 生成状態と通知・取得・本人確認を区別し、版ごとの受領を管理 |
| [12 監査](screenshots/12-audit.png) | 操作の記録、ハッシュ連鎖の整合性、JSONL出力 |
| [13 版の比較](screenshots/13-revision-diff.png) | 旧版を保持して新版を作成し、追加・削除・本文変化を確認 |
| [14 小画面](screenshots/14-mobile-assignment.png) | 幅390pxで課題とフィードバックを閲覧 |

管理画面の「配信停止」は意図した材料欠損試験の版5・8。通常のデモ版11は正常。管理・監査画面の撮影時だけ専用教師にTombの管理権限を付与し、撮影後に解除している。

`build/demo-learner.zip` は学習記録の実物、`build/tomb-0.1.0-alpha.zip` はMoodleへ導入するプラグインであり、用途が異なる。`build/demo-assignment-print.pdf` は課題ページの印刷例。これらの成果物はローカルに作成し、Gitには含めない。
