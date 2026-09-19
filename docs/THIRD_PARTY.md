# 同梱ソフトウェア

| 対象 | 版・出典 | ライセンス・用途 |
|---|---|---|
| `assets/mathjax/` | MathJax 3.2.2、[公式ソース](https://github.com/mathjax/MathJax/tree/3.2.2)、npm `mathjax@3.2.2` の `es5` 配布物 | Apache License 2.0。`assets/mathjax/LICENSE.txt` を同梱。オフライン数式表示 |

MathJax の配布 tarball は取得時に npm の SHA-512 integrity と照合した。HTML からはローカルの配布物のみを読み込む。`ui/safe` を有効にし、TeX由来のURL・任意スタイル等を許可しない。Moodle の MathJax CDN 設定は変更しない。

開発時のブラウザ検証には Playwright 1.63.0 と Chromium を使用した。これらはプラグイン実行時の依存関係ではなく、配布ZIPに含めない。
