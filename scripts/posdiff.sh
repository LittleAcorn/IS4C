#!bin/sh

if [ ! -d "$1" -o ! -d "$2" ]; then
    echo "Usage: posdiff.sh [directory] [directory]"
    exit
fi

diff -r -b -B --exclude="ini.php" --exclude="*.dll" \
    --exclude="*.log" --exclude="*.exe" \
    --exclude="ports.conf" --exclude="log.xml" \
    --exclude="TenderReport.php" --exclude="graphics" \
    --exclude="*.cs" --exclude="*.bmp" \
    --exclude="*~" --exclude="MemcacheStorage*" \
    --exclude="*FilesWrittenAbsolute.txt" --exclude="*.mdb" \
    --exclude="cc-modules" --exclude="fakereceipt.txt" \
    "$1" "$2"
#    --exclude="Paycards" \
