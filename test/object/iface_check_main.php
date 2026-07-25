<?php // @multi @with iface_check_lib.php
#debug 1. dog.speak=Rex barks!
#debug 2. dog.name=Rex
#debug 3. dog.trackId=42
#debug 4. cat.speak=Whiskers meows!
#debug 5. cat.name=Whiskers
#debug 6. cat.trackId=7
#debug 7. done

class Main {
    public function main(): void {
        // Dog: concrete class extending abstract Animal, implementing Named
        $d = new Dog(42, 'Rex');
        echo '1. dog.speak=' . $d->speak() . "\n";
        echo '2. dog.name=' . $d->getName() . "\n";
        echo '3. dog.trackId=' . $d->getTrackId() . "\n";

        // Cat: final class extending abstract Animal
        $c = new Cat(7, 'Whiskers');
        echo '4. cat.speak=' . $c->speak() . "\n";
        echo '5. cat.name=' . $c->getName() . "\n";
        echo '6. cat.trackId=' . $c->getTrackId() . "\n";

        echo "7. done\n";
    }
}
