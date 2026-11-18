"""Adds (or updates) a small Loan Center link in the homepage footer.

Exit code 0 = file changed, 3 = already up to date.
"""
import re
import sys

path = sys.argv[1]
html = open(path, encoding="utf-8").read()
link = ('<!-- mb-loan-center-link --><p style="margin:14px 0 0;font-size:.8rem;text-align:center;opacity:.7">'
        '<a href="/loan/" rel="nofollow">Loan Center</a></p>')
if link in html:
    sys.exit(3)
old = re.compile(r"<!-- mb-loan-center-link -->\s*<p[^>]*>.*?</p>", re.S)
if old.search(html):
    html = old.sub(lambda m: link, html, count=1)
else:
    low = html.lower()
    i = low.rfind("</footer>")
    if i == -1:
        i = low.rfind("</body>")
    if i == -1:
        sys.exit("No </footer> or </body> in index.html")
    html = html[:i] + link + "\n" + html[i:]
open(path, "w", encoding="utf-8").write(html)
