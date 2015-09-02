A Distributed File System that can be used behind an http server (Apache, lighttpd, etc) to implement a highly scalable DFS for storing images, etc.

The aim of this project is to create a DFS that is similar to [MogileFS](http://www.danga.com/mogilefs/). Obviously, Danga was much more clever than I in choosing names for their systems. eh, oh well.

webDFS is mostly based on the algorithms described in these papers ( PDF ):

http://users.soe.ucsc.edu/~elm/Papers/ipdps03.pdf

http://users.soe.ucsc.edu/~elm/Papers/ipdps04.pdf

http://www.ssrc.ucsc.edu/Papers/weil-sc06.pdf

The algorithms come from a family of algorithms known as the RUSH family; Replication Under Scalable Hashing. If built correctly, a system built on the RUSH algorithms will have the following characteristics: (some the text below is taken from the algorithm whitepaper)

  * Ability to map replicated objects to a scalable collection of storage servers or disks without the use of a central directory.

  * Redistributes as few objects as possible when new servers are added or existing servers are removed

  * Guarantees that no two replicas of a particular object are ever placed on the same server.

  * No central directory, clients can compute data locations in parallel, allowing thousands of clients to access objects on thousands of servers simultaneously.

  * Facilitates the distribution of multiple replicas of objects among thousands of disks. Allows individual clients to compute the location of all of the replicas of a particular object in the system algorithmically using just a list of storage servers rather than relying on a directory.

  * Easy scaling management. Scaling out is just a matter of deploying new servers and then propagating a new configuration to all of the nodes. The data will automatically and optimally be moved to accommodate the new resources.

> De-allocating resources is basically the same process in reverse. Simply deploy the new configuration and the data will be moved off the old resources automatically.After the data has been moved, simply take the old resources off line.

  * Easier server management. Since there is no central directory, there are no master or slaves to configure. No master or slaves means that all resources are utilized and no servers sit unused as "hot" spares or backups.

  * No single point of failure. As long as the replica to node ratio is correct, your data will be safe, redundant, and durable; able to withstand major server outages with no loss.

That's pretty cool. I hope that webDFS will capture all of the above for the Web community in a very easy to use and extend package.