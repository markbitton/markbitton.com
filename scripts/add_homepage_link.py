"""Adds a small Loan Center link to the homepage footer (idempotent)."""
import sys

path = sys.argv[1]
html = open(path, encoding="utf-8").read()
if "mb-loan-center-link" in html:
    sys.exit(0)
link = ('<!-- mb-loan-center-link --><p style="margin:14px 0 0;font-size:.8rem;text-align:center;opacity:.7">'
        '<a href="/admin/" rel="nofollow">Loan Center</a></p>')
low = html.lower()
i = low.rfind("</footer>")
if i == -1:
    i = low.rfind("</body>")
if i == -1:
    sys.exit("No </footer> or </body> in index.html")
html = html[:i] + link + "\n" + html[i:]
open(path, "w", encoding="utf-8").write(html)
